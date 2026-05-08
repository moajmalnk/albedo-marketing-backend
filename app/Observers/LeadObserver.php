<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Lead;

class LeadObserver
{
    public function created(Lead $lead): void { $this->write('lead.created', $lead, null, $lead->toArray()); }
    public function updated(Lead $lead): void { $this->write('lead.updated', $lead, $lead->getOriginal(), $lead->getChanges()); }
    public function deleted(Lead $lead): void { $this->write('lead.deleted', $lead, $lead->getOriginal(), null); }

    private function write(string $action, Lead $lead, ?array $old, ?array $new): void
    {
        AuditLog::query()->create(['actor_id' => auth()->id(), 'action' => $action, 'entity_type' => 'lead', 'entity_id' => $lead->id, 'old_values' => $old, 'new_values' => $new, 'ip' => request()?->ip(), 'user_agent' => request()?->userAgent()]);
    }
}
