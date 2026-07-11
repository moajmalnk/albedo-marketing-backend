<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadStage extends Model
{
    protected $fillable = [
        'key',
        'label',
        'group',
        'type',
        'order',
        'color',
        'is_terminal',
        'is_active',
        'description',
        'owner_role',
        'sla_hours',
        'legacy_status',
    ];

    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
            'is_active' => 'boolean',
            'order' => 'integer',
            'sla_hours' => 'integer',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'stage_id');
    }

    public function rulesFrom(): HasMany
    {
        return $this->hasMany(LeadStageRule::class, 'from_stage_id');
    }

    public function rulesTo(): HasMany
    {
        return $this->hasMany(LeadStageRule::class, 'to_stage_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(LeadStagePermission::class, 'lead_stage_id');
    }

    public function automations(): HasMany
    {
        return $this->hasMany(LeadStageAutomation::class, 'lead_stage_id')->orderBy('sort_order');
    }

    public function requiredFields(): HasMany
    {
        return $this->hasMany(LeadStageRequiredField::class, 'lead_stage_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
