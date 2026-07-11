<?php

namespace App\Models;

use App\Traits\Auditable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadStageTransition extends Model
{
    use Auditable;

    protected $fillable = ['lead_id', 'from_stage_id', 'to_stage_id', 'reason', 'changed_by', 'changed_at'];
    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function fromStage(): BelongsTo { return $this->belongsTo(LeadStage::class, 'from_stage_id'); }
    public function toStage(): BelongsTo { return $this->belongsTo(LeadStage::class, 'to_stage_id'); }
    public function changedByUser(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
