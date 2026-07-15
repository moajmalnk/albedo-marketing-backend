<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnknownCall extends Model
{
    protected $fillable = [
        'call_id',
        'direction',
        'from_phone',
        'to_phone',
        'agent_extension',
        'started_at',
        'duration_sec',
        'recording_url',
        'disposition',
        'status',
        'linked_lead_id',
        'linked_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function linkedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'linked_lead_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}
