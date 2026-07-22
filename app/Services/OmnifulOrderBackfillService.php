<?php

namespace App\Services;

use App\Jobs\ProcessOmnifulOrderEvent;
use App\Jobs\RunOmnifulOrderBackfill;
use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderBackfillDay;
use App\Models\OmnifulOrderBackfillRun;
use App\Models\OmnifulOrderEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pulls orders from Omniful by created-date range and enqueues the ones that are
 * MISSING from our DB (dedup by external_id = Omniful order_id), so they flow
 * through the SAME ProcessOmnifulOrderEvent pipeline the webhook uses.
 *
 * Design notes:
 *  - The orchestration runs on its own queue (order_backfill.queue) via
 *    RunOmnifulOrderBackfill, which processes a few list pages per invocation
 *    then re-dispatches a continuation — so a month-long run survives worker
 *    restarts and each job stays short (< queue retry_after).
 *  - The only Omniful API load is this pull loop (list + per-missing detail),
 *    both throttled + 429-aware in HandlesOmnifulOrderFetch. Order PROCESSING
 *    talks to SAP only, so enqueuing thousands adds zero Omniful API load.
 */
class OmnifulOrderBackfillService
{
    /** @var array<string,OmnifulOrderBackfillDay> */
    private array $dayCache = [];

    public function startRun(string $dateFrom, string $dateTo): OmnifulOrderBackfillRun
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $run = OmnifulOrderBackfillRun::create([
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
            'status' => 'queued',
            'last_activity' => 'queued',
        ]);

        // Pre-seed one row per calendar day so the monitor shows the full range
        // (incl. days that turn out to have zero orders) from the very start.
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            OmnifulOrderBackfillDay::firstOrCreate(['run_id' => $run->id, 'day' => $d->format('Y-m-d')]);
        }

        RunOmnifulOrderBackfill::dispatch($run->id)
            ->onQueue((string) config('omniful.order_backfill.queue', 'omniful-backfill'));

        return $run;
    }

    /**
     * Start a backfill from an explicit LIST OF NUMERIC ORDER IDS (e.g. an
     * uploaded "pending orders" export). Each id is pulled from Omniful directly
     * by its order_id — no date paging — and enqueued if it is missing from our
     * DB and its status is not a no-op. The id list is stored to disk so the run
     * survives worker restarts and resumes from `cursor` (the processed offset).
     *
     * @param array<int,string> $ids
     */
    public function startIdListRun(array $ids, string $label): OmnifulOrderBackfillRun
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), $ids),
            fn ($v) => $v !== ''
        )));

        $today = now()->format('Y-m-d');
        $run = OmnifulOrderBackfillRun::create([
            'source_type' => 'id_list',
            'source_label' => trim($label) . ' — ' . count($ids) . ' ids',
            'date_from' => $today,
            'date_to' => $today,
            'status' => 'queued',
            'last_activity' => 'queued',
            'cursor' => '0',
        ]);

        Storage::disk('local')->put($this->idListPath($run), implode("\n", $ids));

        RunOmnifulOrderBackfill::dispatch($run->id)
            ->onQueue((string) config('omniful.order_backfill.queue', 'omniful-backfill'));

        return $run;
    }

    private function idListPath(OmnifulOrderBackfillRun $run): string
    {
        return 'backfill/run_' . $run->id . '_ids.txt';
    }

    /**
     * Process one batch of an id_list run: for each numeric order id, skip if we
     * already have it, else pull it from Omniful by order_id and enqueue it (the
     * SAME ProcessOmnifulOrderEvent pipeline the webhook + date backfill use).
     * Advances `cursor` by the number of ids handled; the job re-dispatches until
     * the whole list is done (or a cancel is honored).
     */
    public function runIdBatch(OmnifulOrderBackfillRun $run): void
    {
        $run->refresh();
        if ($run->status === 'cancel_requested') {
            $this->finish($run, 'cancelled');

            return;
        }
        if (!in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        if ($run->status === 'queued' || $run->started_at === null) {
            $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
        }

        $path = $this->idListPath($run);
        if (!Storage::disk('local')->exists($path)) {
            $run->update(['last_error' => 'ID list file missing for run ' . $run->id]);
            $this->finish($run, 'failed');

            return;
        }

        $all = array_values(array_filter(
            array_map('trim', explode("\n", (string) Storage::disk('local')->get($path))),
            fn ($v) => $v !== ''
        ));
        $total = count($all);
        $offset = (int) ($run->cursor ?: 0);
        $batchSize = max(1, (int) config('omniful.order_backfill.id_batch_size', 60));
        $client = app(OmnifulApiClient::class);

        $runDelta = ['scanned' => 0, 'existing' => 0, 'missing' => 0, 'skipped' => 0, 'enqueued' => 0];
        $dayDelta = [];

        $i = 0;
        for (; $i < $batchSize && ($offset + $i) < $total; $i++) {
            if (OmnifulOrderBackfillRun::whereKey($run->id)->value('status') === 'cancel_requested') {
                $this->applyRunDelta($run, $runDelta);
                foreach ($dayDelta as $day => $d) {
                    $this->applyDayDelta($run, (string) $day, $d);
                }
                OmnifulOrderBackfillRun::whereKey($run->id)->update(['cursor' => (string) ($offset + $i)]);
                $this->finish($run, 'cancelled');

                return;
            }

            $id = trim($all[$offset + $i]);
            $runDelta['scanned']++;
            if ($id === '') {
                continue;
            }

            if (OmnifulOrder::where('external_id', $id)->exists()) {
                $runDelta['existing']++;

                continue;
            }

            $lookup = $client->fetchSellerOrderByNumericId($id);
            if (($lookup['rl_hits'] ?? 0) > 0) {
                OmnifulOrderBackfillRun::whereKey($run->id)->increment('rate_limit_hits', $lookup['rl_hits']);
            }

            // Not found in Omniful (or the lookup failed): count as missing but not
            // enqueued — the missing-vs-enqueued gap surfaces unpullable ids.
            if (!($lookup['ok'] ?? false) || !is_array($lookup['order'] ?? null)) {
                $runDelta['missing']++;

                continue;
            }

            $row = (array) $lookup['order'];
            $day = $this->createdDay($run, $row);
            $dayDelta[$day]['total'] = ($dayDelta[$day]['total'] ?? 0) + 1;

            if ($this->isSkippableStatus((string) ($row['status_code'] ?? $row['status'] ?? ''))) {
                $runDelta['skipped']++;
                $dayDelta[$day]['skipped'] = ($dayDelta[$day]['skipped'] ?? 0) + 1;

                continue;
            }

            $runDelta['missing']++;
            $dayDelta[$day]['missing'] = ($dayDelta[$day]['missing'] ?? 0) + 1;

            // $row carries `id` (hash) → enqueueMissingOrder detail-fetches it for
            // order_items, upserts the order, and dispatches the SAP job.
            if ($this->enqueueMissingOrder($run, $row, $id)) {
                $runDelta['enqueued']++;
                $dayDelta[$day]['enqueued'] = ($dayDelta[$day]['enqueued'] ?? 0) + 1;
            }
        }

        $this->applyRunDelta($run, $runDelta);
        foreach ($dayDelta as $day => $d) {
            $this->applyDayDelta($run, (string) $day, $d);
        }
        OmnifulOrderBackfillRun::whereKey($run->id)->increment('pages');

        $newOffset = $offset + $i;
        $run->refresh();
        $run->update([
            'cursor' => (string) $newOffset,
            'last_error' => null,
            'last_activity' => 'processed ' . $newOffset . ' / ' . $total . ' ids',
        ]);

        if ($newOffset >= $total) {
            $this->finish($run, 'completed');
        }
    }

    public function requestCancel(OmnifulOrderBackfillRun $run): void
    {
        if ($run->isActive()) {
            $run->update(['status' => 'cancel_requested', 'last_activity' => 'cancel requested']);
        }
    }

    /**
     * Process one batch of list pages for a run. Returns after either the range
     * is exhausted (status -> completed), a cancel is honored (-> cancelled), or
     * the per-batch page budget is spent (status stays running -> the job
     * re-dispatches a continuation). Throws on a transient list-fetch failure so
     * the queue retries the batch from the last saved cursor.
     */
    public function runBatch(OmnifulOrderBackfillRun $run): void
    {
        $run->refresh();
        if ($run->status === 'cancel_requested') {
            $this->finish($run, 'cancelled');

            return;
        }
        if (!in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        if ($run->status === 'queued' || $run->started_at === null) {
            $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
        }

        $client = app(OmnifulApiClient::class);
        $from = $run->date_from->format('Y-m-d');
        $to = $run->date_to->format('Y-m-d');
        $perPage = max(1, (int) config('omniful.order_backfill.per_page', 100));
        $pagesPerBatch = max(1, (int) config('omniful.order_backfill.pages_per_batch', 3));
        $cursor = $run->cursor ?: null;

        for ($i = 0; $i < $pagesPerBatch; $i++) {
            if (OmnifulOrderBackfillRun::whereKey($run->id)->value('status') === 'cancel_requested') {
                $this->finish($run, 'cancelled');

                return;
            }

            $page = $client->fetchSellerOrdersPage($from, $to, $perPage, $cursor);
            if (($page['rl_hits'] ?? 0) > 0) {
                OmnifulOrderBackfillRun::whereKey($run->id)->increment('rate_limit_hits', $page['rl_hits']);
            }

            if (!($page['ok'] ?? false)) {
                $run->update([
                    'last_error' => 'List fetch failed: HTTP ' . ($page['status'] ?? 0) . ' ' . substr((string) ($page['body'] ?? ''), 0, 400),
                    'last_activity' => 'list fetch failed (HTTP ' . ($page['status'] ?? 0) . ') — retrying',
                ]);
                // Let the queue retry this batch from the saved cursor.
                throw new \RuntimeException('Omniful order list fetch failed: HTTP ' . ($page['status'] ?? 0));
            }

            $this->ingestPage($run, (array) $page['rows']);

            $cursor = ($page['end_cursor'] ?? '') !== '' ? $page['end_cursor'] : null;
            OmnifulOrderBackfillRun::whereKey($run->id)->increment('pages');
            $run->refresh();
            $run->update([
                'cursor' => $cursor,
                'last_error' => null,
                'last_activity' => 'scanned ' . $run->scanned . ' orders (' . $run->pages . ' pages)',
            ]);

            if (!($page['has_next'] ?? false) || $cursor === null) {
                $this->finish($run, 'completed');

                return;
            }
        }

        // Budget spent, more pages remain: leave running so the job continues.
        $run->update(['last_activity' => 'batch done; continuing at next page']);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function ingestPage(OmnifulOrderBackfillRun $run, array $rows): void
    {
        $runDelta = ['scanned' => 0, 'existing' => 0, 'missing' => 0, 'skipped' => 0, 'enqueued' => 0];
        $dayDelta = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $day = $this->createdDay($run, $data);

            $runDelta['scanned']++;
            $dayDelta[$day]['total'] = ($dayDelta[$day]['total'] ?? 0) + 1;

            // No-op statuses (on_hold / picked / packed) never produce a SAP
            // document, so skip them entirely — no detail fetch, no enqueue, and
            // NOT counted as "missing" (that would be misleading + wasted work).
            if ($this->isSkippableStatus((string) ($data['status_code'] ?? $data['status'] ?? ''))) {
                $runDelta['skipped']++;
                $dayDelta[$day]['skipped'] = ($dayDelta[$day]['skipped'] ?? 0) + 1;

                continue;
            }

            $externalId = $this->externalIdFor($data);
            if ($externalId === '') {
                continue; // nothing to key on — counted as scanned only
            }

            if (OmnifulOrder::where('external_id', $externalId)->exists()) {
                $runDelta['existing']++;
                $dayDelta[$day]['existing'] = ($dayDelta[$day]['existing'] ?? 0) + 1;

                continue;
            }

            $runDelta['missing']++;
            $dayDelta[$day]['missing'] = ($dayDelta[$day]['missing'] ?? 0) + 1;

            if ($this->enqueueMissingOrder($run, $data, $externalId)) {
                $runDelta['enqueued']++;
                $dayDelta[$day]['enqueued'] = ($dayDelta[$day]['enqueued'] ?? 0) + 1;
            }
        }

        $this->applyRunDelta($run, $runDelta);
        foreach ($dayDelta as $day => $delta) {
            $this->applyDayDelta($run, (string) $day, $delta);
        }
    }

    /**
     * Fetch full detail (with order_items), build the webhook-equivalent payload,
     * upsert the order row, create the event, and dispatch the same job the
     * webhook dispatches. Returns true when an order was enqueued.
     *
     * @param array<string,mixed> $listData
     */
    private function enqueueMissingOrder(OmnifulOrderBackfillRun $run, array $listData, string $externalId): bool
    {
        $data = $listData;
        $hash = trim((string) ($listData['id'] ?? $listData['omniful_order_id'] ?? ''));
        if ($hash !== '') {
            $detail = app(OmnifulApiClient::class)->fetchSellerOrderDetail($hash);
            if (($detail['rl_hits'] ?? 0) > 0) {
                OmnifulOrderBackfillRun::whereKey($run->id)->increment('rate_limit_hits', $detail['rl_hits']);
            }
            if (($detail['ok'] ?? false) && is_array($detail['order'] ?? null)) {
                $data = $detail['order'];
            }
        }

        $eventName = $this->eventNameForStatus((string) ($data['status_code'] ?? $data['status'] ?? ''));
        $payload = ['event_name' => $eventName, 'data' => $data];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $payloadHash = hash('sha256', is_string($encoded) ? $encoded : ('backfill|' . $externalId));

        // Upsert the order row FIRST — process() bails if it is missing.
        OmnifulOrder::updateOrCreate(
            ['external_id' => $externalId],
            [
                'omniful_status' => (string) ($data['status_code'] ?? $data['status'] ?? ''),
                'last_event_type' => 'order',
                'last_event_at' => now(),
                'last_payload' => $payload,
                'last_headers' => ['source' => 'backfill', 'backfill_run_id' => $run->id],
            ]
        );

        // firstOrCreate on payload_hash keeps a re-run idempotent (same as the
        // webhook's payload_hash dedup).
        $event = OmnifulOrderEvent::firstOrCreate(
            ['payload_hash' => $payloadHash],
            [
                'external_id' => $externalId,
                'payload' => $payload,
                'headers' => ['source' => 'backfill', 'backfill_run_id' => $run->id],
                'signature_valid' => null,
                'received_at' => now(),
            ]
        );

        ProcessOmnifulOrderEvent::dispatch($event->id, (bool) config('omniful.order_backfill.force', false))
            ->onQueue((string) config('omniful.order_backfill.target_queue', 'omniful-orders'));

        return true;
    }

    /**
     * Map the pulled order's current status to the webhook event_name keyword
     * the classifier keys off (invoice: create/new, delivery: ship/deliver,
     * credit: cancel). The accurate status_code travels in the payload too, so
     * no-op statuses (e.g. on_hold) are still filtered by classifyEventForProcessing.
     */
    private function eventNameForStatus(string $status): string
    {
        $s = strtolower(trim($status));
        if ($s !== '' && (str_contains($s, 'cancel') || str_contains($s, 'return') || str_contains($s, 'refund'))) {
            return 'order.cancelled.event';
        }
        if ($s !== '' && (str_contains($s, 'deliver') || str_contains($s, 'ship') || str_contains($s, 'complete') || str_contains($s, 'fulfil'))) {
            return 'order.shipped.event';
        }

        return 'order.create.event';
    }

    /**
     * True for statuses the webhook pipeline treats as no-op (on_hold / picked /
     * packed) — the backfill skips them rather than pull + enqueue for nothing.
     * Configurable via omniful.order_backfill.skip_statuses.
     */
    private function isSkippableStatus(string $status): bool
    {
        $s = strtolower(trim($status));
        if ($s === '') {
            return false;
        }

        return in_array($s, (array) config('omniful.order_backfill.skip_statuses', []), true);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function externalIdFor(array $data): string
    {
        foreach (['display_id', 'order_id', 'id'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $data
     */
    private function createdDay(OmnifulOrderBackfillRun $run, array $data): string
    {
        $raw = trim((string) ($data['order_created_at'] ?? $data['created_at'] ?? ''));
        if ($raw !== '') {
            try {
                return Carbon::parse($raw)->format('Y-m-d');
            } catch (\Throwable) {
                // fall through
            }
        }

        return $run->date_from->format('Y-m-d');
    }

    private function dayRow(OmnifulOrderBackfillRun $run, string $day): OmnifulOrderBackfillDay
    {
        $key = $run->id . '|' . $day;

        return $this->dayCache[$key] ??= OmnifulOrderBackfillDay::firstOrCreate([
            'run_id' => $run->id,
            'day' => $day,
        ]);
    }

    /**
     * @param array{scanned:int,existing:int,missing:int,enqueued:int} $delta
     */
    private function applyRunDelta(OmnifulOrderBackfillRun $run, array $delta): void
    {
        $set = [];
        foreach (['scanned', 'existing', 'missing', 'skipped', 'enqueued'] as $col) {
            if (($delta[$col] ?? 0) > 0) {
                $set[$col] = DB::raw($col . ' + ' . (int) $delta[$col]);
            }
        }
        if ($set !== []) {
            OmnifulOrderBackfillRun::whereKey($run->id)->update($set);
            $run->refresh();
        }
    }

    /**
     * @param array<string,int> $delta
     */
    private function applyDayDelta(OmnifulOrderBackfillRun $run, string $day, array $delta): void
    {
        $set = [];
        foreach (['total', 'existing', 'missing', 'skipped', 'enqueued'] as $col) {
            if (($delta[$col] ?? 0) > 0) {
                $set[$col] = DB::raw($col . ' + ' . (int) $delta[$col]);
            }
        }
        if ($set === []) {
            return;
        }
        $row = $this->dayRow($run, $day);
        OmnifulOrderBackfillDay::whereKey($row->id)->update($set);
    }

    private function finish(OmnifulOrderBackfillRun $run, string $status): void
    {
        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'last_activity' => $status,
        ]);
    }
}
