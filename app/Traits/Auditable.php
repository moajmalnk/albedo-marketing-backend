<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable()
    {
        static::created(function (Model $model) {
            $model->auditAction("created");
        });

        static::updated(function (Model $model) {
            $model->auditAction("updated");
        });

        static::deleted(function (Model $model) {
            $model->auditAction("deleted");
        });
    }

    public function auditAction(string $event, ?array $customOld = null, ?array $customNew = null)
    {
        $entityType = strtolower(class_basename($this));
        
        $oldValues = $customOld;
        $newValues = $customNew;

        if ($oldValues === null && $newValues === null) {
            if ($event === "updated") {
                $oldValues = $this->getOriginal();
                $newValues = $this->getChanges();
                
                // Filter out updated_at if it is the only change
                if (isset($oldValues["updated_at"])) {
                    unset($oldValues["updated_at"]);
                }
                if (isset($newValues["updated_at"])) {
                    unset($newValues["updated_at"]);
                }
            } elseif ($event === "created") {
                $newValues = $this->getAttributes();
            } elseif ($event === "deleted") {
                $oldValues = $this->getAttributes();
            }
        }

        // Check if there are actual changes (excluding updated_at) for an update
        if ($event === "updated" && empty($newValues)) {
            return;
        }

        // Hide sensitive fields
        $hidden = array_merge($this->getHidden(), ["password", "remember_token"]);
        if ($oldValues) {
            foreach ($hidden as $field) {
                if (array_key_exists($field, $oldValues)) $oldValues[$field] = "***";
            }
        }
        if ($newValues) {
            foreach ($hidden as $field) {
                if (array_key_exists($field, $newValues)) $newValues[$field] = "***";
            }
        }

        AuditLog::query()->create([
            "actor_id" => auth()->check() ? auth()->id() : null,
            "action" => "{$entityType}.{$event}",
            "entity_type" => $entityType,
            "entity_id" => $this->getKey(),
            "old_values" => empty($oldValues) ? null : $oldValues,
            "new_values" => empty($newValues) ? null : $newValues,
            "ip" => request()?->ip(),
            "user_agent" => request()?->userAgent(),
        ]);
    }
}
