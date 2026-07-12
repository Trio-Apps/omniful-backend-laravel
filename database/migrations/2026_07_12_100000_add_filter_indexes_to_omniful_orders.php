<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Omniful Order Monitor renders a SelectFilter for each of these
     * columns, and Filament computes their distinct options on every page load.
     * Without an index a `SELECT DISTINCT` full-scans the ~31k-row / 4.3 GB
     * omniful_orders table (~12.5s EACH — ~25s of the page load), while the
     * already-indexed sap_status filter returns in ~7ms. Index them to match.
     */
    private array $columns = ['omniful_status', 'last_event_type'];

    public function up(): void
    {
        foreach ($this->columns as $column) {
            if ($this->indexMissing($column)) {
                Schema::table('omniful_orders', function (Blueprint $table) use ($column) {
                    $table->index($column);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $column) {
            if (!$this->indexMissing($column)) {
                Schema::table('omniful_orders', function (Blueprint $table) use ($column) {
                    $table->dropIndex("omniful_orders_{$column}_index");
                });
            }
        }
    }

    /**
     * Guard so the migration is safe even where the index was already created
     * by hand (e.g. as an emergency fix on the live server).
     */
    private function indexMissing(string $column): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['omniful_orders', "omniful_orders_{$column}_index"]
        );

        return (int) ($row->c ?? 0) === 0;
    }
};
