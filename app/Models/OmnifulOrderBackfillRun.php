<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OmnifulOrderBackfillRun extends Model
{
    protected $fillable = [
        'date_from',
        'date_to',
        'status',
        'cursor',
        'scanned',
        'existing',
        'missing',
        'enqueued',
        'pages',
        'rate_limit_hits',
        'last_error',
        'last_activity',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function days(): HasMany
    {
        return $this->hasMany(OmnifulOrderBackfillDay::class, 'run_id')->orderBy('day');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running', 'cancel_requested'], true);
    }
}
