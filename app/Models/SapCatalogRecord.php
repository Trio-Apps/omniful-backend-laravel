<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapCatalogRecord extends Model
{
    protected $fillable = [
        'resource',
        'module',
        'sap_path',
        'external_key',
        'name',
        'payload',
        'synced_at',
        'status',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
