<?php

namespace App\Console\Commands;

use App\Services\MasterData\SapItemIntegrationService;
use Illuminate\Console\Command;

class IntegrateSapItems extends Command
{
    protected $signature = 'sap:integrate-items {--limit=0 : Max items to process this run (0 = unlimited / config default)}';

    protected $description = 'Integrate not-yet-integrated SAP items into Omniful as SKUs (inventory items) or KITs (sales-only combos from the ZIDCOMBO UDO), then stamp the SAP integration UDF flags.';

    public function handle(SapItemIntegrationService $service): int
    {
        $limit = (int) $this->option('limit');

        $this->info('Reading SAP items pending integration (' . config('omniful.item_integration.integrated_udf_field') . ' = ' . config('omniful.item_integration.not_integrated_value') . ')...');

        try {
            $summary = $service->run($limit);
        } catch (\Throwable $e) {
            $this->error('Integration failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('Total pending:    ' . $summary['total']);
        $this->line('SKUs created:     ' . $summary['skus_created']);
        $this->line('KITs created:     ' . $summary['kits_created']);
        $this->line('Ignored (no combo): ' . $summary['ignored_no_combo']);
        $this->line('Skipped (other):  ' . $summary['skipped_other']);
        $this->line('Failed:           ' . $summary['failed']);

        foreach (array_slice((array) $summary['errors'], 0, 10) as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
