<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class LeadStageTransition extends Model
{
    protected $fillable = ['lead_id', 'from_stage_id', 'to_stage_id', 'reason', 'changed_by', 'changed_at'];
    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
}
