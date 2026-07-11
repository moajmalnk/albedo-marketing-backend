<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignmentLog extends Model
{
    protected $table = 'lead_assignment_logs';

    protected $fillable = [
        'lead_id',
        'old_owner_id',
        'new_owner_id',
        'assignment_type',
        'reason',
        'assigned_by',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function oldOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'old_owner_id');
    }

    public function newOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_owner_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
