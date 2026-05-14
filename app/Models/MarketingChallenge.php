<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingChallenge extends Model
{
    protected $table = 'marketing_challenges';

    protected $fillable = [
        'category',
        'description',
        'department',
        'reported_by',
        'affected_leads',
        'status',
        'date_reported',
        'date_resolved',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'affected_leads' => 'array',
            'date_reported' => 'date',
            'date_resolved' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
