<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('integration_settings', 'environment')) {
                $table->string('environment')->default('production')->after('id');
            }
            if (!Schema::hasColumn('integration_settings', 'is_active')) {
                $table->boolean('is_active')->default(false)->after('environment');
            }
        });

        // Mark the existing (singleton) row as the active Production profile.
        $existing = DB::table('integration_settings')->orderBy('id')->first();
        if ($existing !== null) {
            DB::table('integration_settings')
                ->where('id', $existing->id)
                ->update(['environment' => 'production', 'is_active' => 1]);

            // Seed a Staging profile as a full copy of Production (a working
            // template). It is NOT active; the user edits its connection and
            // flips the toggle when ready.
            $hasStaging = DB::table('integration_settings')->where('environment', 'staging')->exists();
            if (!$hasStaging) {
                $row = (array) DB::table('integration_settings')->where('id', $existing->id)->first();
                unset($row['id']);
                $row['environment'] = 'staging';
                $row['is_active'] = 0;
                $row['created_at'] = now();
                $row['updated_at'] = now();
                DB::table('integration_settings')->insert($row);
            }
        } else {
            // Fresh install: create both profiles, Production active.
            DB::table('integration_settings')->insert([
                ['environment' => 'production', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['environment' => 'staging', 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        // Keep only the active row's data on the original id, then drop columns.
        Schema::table('integration_settings', function (Blueprint $table) {
            if (Schema::hasColumn('integration_settings', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('integration_settings', 'environment')) {
                $table->dropColumn('environment');
            }
        });
    }
};
