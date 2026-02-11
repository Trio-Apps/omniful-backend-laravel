<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapCostCenterSetting extends Model
{
    protected $fillable = [
        'costing_code',
        'costing_code2',
        'costing_code3',
        'costing_code4',
        'costing_code5',
        'project_code',
        'apply_to_stock_transfer',
        'last_synced_at',
    ];

    protected $casts = [
        'apply_to_stock_transfer' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}

