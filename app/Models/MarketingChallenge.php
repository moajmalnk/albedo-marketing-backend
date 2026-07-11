<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingChallenge extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'marketing_challenges';

    protected $fillable = [
        'category',
        'description',
        'department',
        'reported_by',
        'affected_leads',
        'status',
        'priority',
        'assigned_to',
        'assigned_by',
        'assigned_at',
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
            'assigned_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ChallengeComment::class, 'challenge_id');
    }
}
