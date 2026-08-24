<?php

namespace App\Console\Commands;

use App\Models\OmnifulStockTransferEvent;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Correct SAP stock transfers that moved a quantity other than what the
 * destination hub actually RECEIVED.
 *
 * Older transfers posted the APPROVED quantity (and later the SHIPPED one),
 * because `received_quantity` is exposed only by Omniful's dedicated STO
 * endpoint. Where SAP moved MORE than was received, a compensating transfer is
 * posted back (destination -> source) for the excess; where it moved LESS, a
 * top-up transfer (source -> destination) is posted. The originals are left
 * untouched — SAP cannot edit a posted transfer, and cancelling one would
 * disturb costing far more than a compensating document does.
 *
 * Idempotent: each correction carries the reference "<sto>-qtyfix" (or
 * "-qtyfix-up"), and a transfer already holding it is never posted twice.
 */
class FixStockTransferQuantities extends Command
{
    protected $signature = 'omniful:fix-sto-quantities {--dry-run} {--limit=0} {--sto=}';

    protected $description = 'Post compensating stock transfers where SAP moved a different quantity than Omniful received.';

    public function handle(SapServiceLayerClient $sap, OmnifulApiClient $omniful): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $only = trim((string) $this->option('sto'));

        $get = new \ReflectionMethod($sap, 'get');
        $get->setAccessible(true);

        $query = OmnifulStockTransferEvent::query()
            ->whereIn('sap_status', ['created', 'created_via_transit'])
            ->whereNotNull('sap_doc_entry')
            ->orderByDesc('id');
        if ($only !== '') {
            $query->where('external_id', $only);
        }
        $events = $query->get(['external_id', 'sap_doc_entry', 'sap_doc_num']);

        $stats = ['seen' => 0, 'ok' => 0, 'fixed' => 0, 'already_fixed' => 0, 'no_receipt' => 0, 'error' => 0];
        $unitsBack = 0.0;
        $unitsUp = 0.0;
        $done = 0;

        foreach ($events as $event) {
            $sto = (string) $event->external_id;
            $stats['seen']++;

            try {
                // What the destination actually received, per SKU.
                $recv = $omniful->fetchStockTransferReceivedQuantities($sto);
                if (($recv['ok'] ?? false) !== true) {
                    $stats['no_receipt']++;

                    continue;
                }
                $received = (array) $recv['quantities'];

                // What SAP actually moved, per SKU (+ the direction it used).
                $doc = (array) ($get->invoke($sap, '/StockTransfers(' . (int) $event->sap_doc_entry . ')')->json());
                $moved = [];
                $from = trim((string) ($doc['FromWarehouse'] ?? ''));
                $to = trim((string) ($doc['ToWarehouse'] ?? ''));
                foreach ((array) ($doc['StockTransferLines'] ?? []) as $line) {
                    $code = (string) ($line['ItemCode'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    $moved[$code] = ((float) ($moved[$code] ?? 0)) + (float) ($line['Quantity'] ?? 0);
                    $from = $from !== '' ? $from : trim((string) ($line['FromWarehouseCode'] ?? ''));
                    $to = $to !== '' ? $to : trim((string) ($line['WarehouseCode'] ?? ''));
                }
                if ($from === '' || $to === '') {
                    $stats['error']++;
                    Log::warning('STO qty fix: could not resolve warehouses', ['sto' => $sto]);

                    continue;
                }

                // Excess (SAP moved more) goes BACK; a shortfall goes forward.
                $back = [];
                $up = [];
                foreach ($moved as $code => $qty) {
                    if (!array_key_exists($code, $received)) {
                        continue; // not part of the receipt — leave alone
                    }
                    $delta = round($qty - (float) $received[$code], 4);
                    if ($delta > 0.001) {
                        $back[] = ['seller_sku_code' => $code, 'quantity' => $delta];
                    } elseif ($delta < -0.001) {
                        $up[] = ['seller_sku_code' => $code, 'quantity' => abs($delta)];
                    }
                }

                if ($back === [] && $up === []) {
                    $stats['ok']++;

                    continue;
                }

                $refBack = $sto . '-qtyfix';
                $refUp = $sto . '-qtyfix-up';
                if (($back !== [] && $sap->findExistingStockTransferByReference($refBack) !== null)
                    || ($up !== [] && $sap->findExistingStockTransferByReference($refUp) !== null)) {
                    $stats['already_fixed']++;

                    continue;
                }

                $backQty = array_sum(array_column($back, 'quantity'));
                $upQty = array_sum(array_column($up, 'quantity'));
                $this->line(sprintf(
                    '%s (SAP %s) %s -> %s | back %s units, up %s units',
                    $sto,
                    (string) $event->sap_doc_num,
                    $from,
                    $to,
                    (string) $backQty,
                    (string) $upQty
                ));

                if ($dry) {
                    $stats['fixed']++;
                    $unitsBack += $backQty;
                    $unitsUp += $upQty;

                    continue;
                }

                if ($back !== []) {
                    // Reverse direction: return the excess to the source hub.
                    $res = $sap->createStockTransfer(
                        $back,
                        $to,
                        $from,
                        'Omniful qty correction (received) | ' . $sto,
                        $refBack,
                        ''
                    );
                    if (($res['ignored'] ?? false) === true) {
                        throw new \RuntimeException('back transfer ignored: ' . (string) ($res['reason'] ?? ''));
                    }
                    $unitsBack += $backQty;
                }

                if ($up !== []) {
                    $res = $sap->createStockTransfer(
                        $up,
                        $from,
                        $to,
                        'Omniful qty correction (received, top-up) | ' . $sto,
                        $refUp,
                        ''
                    );
                    if (($res['ignored'] ?? false) === true) {
                        throw new \RuntimeException('top-up transfer ignored: ' . (string) ($res['reason'] ?? ''));
                    }
                    $unitsUp += $upQty;
                }

                $stats['fixed']++;
            } catch (\Throwable $e) {
                $stats['error']++;
                Log::warning('STO qty fix failed', ['sto' => $sto, 'error' => substr($e->getMessage(), 0, 300)]);
                $this->warn('  ! ' . $sto . ': ' . substr($e->getMessage(), 0, 160));
                usleep(400000);
            }

            $done++;
            if ($limit > 0 && $done >= $limit) {
                break;
            }
            usleep(300000);
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . 'DONE: ' . json_encode($stats, JSON_UNESCAPED_SLASHES)
            . ' | units returned=' . round($unitsBack) . ' units topped-up=' . round($unitsUp));

        return self::SUCCESS;
    }
}
