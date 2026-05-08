<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void { AuditLog::query()->create(['actor_id' => auth()->id(), 'action' => 'user.created', 'entity_type' => 'user', 'entity_id' => $user->id, 'new_values' => $user->toArray()]); }
    public function updated(User $user): void { AuditLog::query()->create(['actor_id' => auth()->id(), 'action' => 'user.updated', 'entity_type' => 'user', 'entity_id' => $user->id, 'old_values' => $user->getOriginal(), 'new_values' => $user->getChanges()]); }
    public function deleted(User $user): void { AuditLog::query()->create(['actor_id' => auth()->id(), 'action' => 'user.deleted', 'entity_type' => 'user', 'entity_id' => $user->id]); }
}
