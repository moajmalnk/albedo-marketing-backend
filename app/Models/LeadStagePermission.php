<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadStagePermission extends Model
{
    protected $fillable = [
        'lead_stage_id',
        'role',
        'can_view',
        'can_move',
        'can_override',
        'can_close',
        'can_reopen',
        'can_delete',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_move' => 'boolean',
            'can_override' => 'boolean',
            'can_close' => 'boolean',
            'can_reopen' => 'boolean',
            'can_delete' => 'boolean',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(LeadStage::class, 'lead_stage_id');
    }
}
