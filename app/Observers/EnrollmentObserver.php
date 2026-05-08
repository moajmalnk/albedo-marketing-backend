<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Enrollment;

class EnrollmentObserver
{
    public function created(Enrollment $enrollment): void { AuditLog::query()->create(['actor_id' => auth()->id(), 'action' => 'enrollment.created', 'entity_type' => 'enrollment', 'entity_id' => $enrollment->id, 'new_values' => $enrollment->toArray()]); }
    public function updated(Enrollment $enrollment): void { AuditLog::query()->create(['actor_id' => auth()->id(), 'action' => 'enrollment.updated', 'entity_type' => 'enrollment', 'entity_id' => $enrollment->id, 'old_values' => $enrollment->getOriginal(), 'new_values' => $enrollment->getChanges()]); }
    public function deleted(Enrollment $enrollment): void { AuditLog::query()->create(['actor_id' => auth()->id(), 'action' => 'enrollment.deleted', 'entity_type' => 'enrollment', 'entity_id' => $enrollment->id]); }
}
