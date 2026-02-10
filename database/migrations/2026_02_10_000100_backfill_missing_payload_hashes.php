<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'omniful_order_events',
            'omniful_return_order_events',
            'omniful_purchase_order_events',
            'omniful_inwarding_events',
            'omniful_inventory_events',
            'omniful_product_events',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasColumn($table, 'payload_hash')) {
                continue;
            }

            DB::table($table)
                ->select(['id', 'payload'])
                ->whereNull('payload_hash')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        $payloadString = $this->normalizePayloadForHash($row->payload ?? null);
                        if ($payloadString === null) {
                            continue;
                        }

                        $hash = hash('sha256', $payloadString);
                        $exists = DB::table($table)
                            ->where('payload_hash', $hash)
                            ->exists();

                        if ($exists) {
                            // Keep historical rows unique without breaking migration.
                            $hash = hash('sha256', $payloadString . '#legacy-dup-' . $row->id);
                        }

                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['payload_hash' => $hash]);
                    }
                }, 'id');
        }
    }

    public function down(): void
    {
        // Intentionally left blank. Payload hashes are data-repair values.
    }

    private function normalizePayloadForHash(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            return $payload;
        }

        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }
};

