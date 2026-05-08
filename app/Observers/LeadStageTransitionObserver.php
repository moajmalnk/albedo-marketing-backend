<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\LeadStageTransition;

class LeadStageTransitionObserver
{
    public function created(LeadStageTransition $leadStageTransition): void
    {
        AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'action' => 'lead.stage_change',
            'entity_type' => 'lead_stage_transition',
            'entity_id' => $leadStageTransition->id,
            'new_values' => $leadStageTransition->toArray(),
        ]);
    }
}
