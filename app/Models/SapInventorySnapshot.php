<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last quantity we pushed to Omniful for a SAP warehouse + item. Delta runs
 * compare the freshly-read SAP Available against this to push only changes.
 */
class SapInventorySnapshot extends Model
{
    protected $fillable = [
        'warehouse_code',
        'item_code',
        'hub_code',
        'quantity',
        'last_pushed_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'last_pushed_at' => 'datetime',
    ];
}
