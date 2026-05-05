<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omniful_stock_transfer_events', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->index();
            $table->json('payload');
            $table->string('payload_hash', 64)->nullable()->unique();
            $table->json('headers')->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->string('sap_status')->nullable();
            $table->string('sap_doc_entry')->nullable();
            $table->string('sap_doc_num')->nullable();
            $table->text('sap_error')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });

        if (!Schema::hasTable('omniful_inventory_events')) {
            return;
        }

        DB::table('omniful_inventory_events')
            ->orderBy('id')
            ->chunkById(200, function ($events): void {
                foreach ($events as $event) {
                    $payload = json_decode((string) $event->payload, true);
                    if (!is_array($payload) || !$this->isStockTransferPayload($payload)) {
                        continue;
                    }

                    $payloadHash = $event->payload_hash ?: hash('sha256', (string) $event->payload);

                    DB::table('omniful_stock_transfer_events')->updateOrInsert(
                        ['payload_hash' => $payloadHash],
                        [
                            'external_id' => $event->external_id,
                            'payload' => $event->payload,
                            'headers' => $event->headers,
                            'signature_valid' => $event->signature_valid,
                            'sap_status' => $event->sap_status,
                            'sap_doc_entry' => $event->sap_doc_entry,
                            'sap_doc_num' => $event->sap_doc_num,
                            'sap_error' => $event->sap_error,
                            'received_at' => $event->received_at,
                            'created_at' => $event->created_at,
                            'updated_at' => $event->updated_at,
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('omniful_stock_transfer_events');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function isStockTransferPayload(array $payload): bool
    {
        $eventName = strtolower((string) data_get($payload, 'event_name', ''));
        $action = strtolower((string) data_get($payload, 'action', ''));
        $entity = strtolower((string) data_get($payload, 'entity', ''));

        return str_contains($eventName, 'stock_transfer')
            || str_contains($eventName, 'stock-transfer')
            || str_contains($action, 'stock_transfer')
            || str_contains($action, 'stock-transfer')
            || str_contains($entity, 'stock_transfer')
            || str_contains($entity, 'stock-transfer');
    }
};
