<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    private const ALLOWED_STATUS_TRANSITIONS = [
        'draft' => ['scheduled', 'active', 'paused', 'completed', 'archived'],
        'scheduled' => ['active', 'paused', 'completed', 'archived'],
        'active' => ['paused', 'completed', 'archived'],
        'paused' => ['active', 'completed', 'archived'],
        'completed' => ['archived'],
        'archived' => ['draft', 'active'] // Allow restoring
    ];

    public function transitionStatus(Campaign $campaign, string $newStatus, User $actor): void
    {
        $currentStatus = $campaign->status;

        if ($currentStatus === $newStatus) {
            return;
        }

        $allowed = self::ALLOWED_STATUS_TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Invalid status transition from '{$currentStatus}' to '{$newStatus}'."]
            ]);
        }

        DB::transaction(function () use ($campaign, $newStatus, $actor) {
            $oldValues = ['status' => $campaign->status];
            $campaign->update(['status' => $newStatus, 'updated_by' => $actor->id]);

            $this->logActivity($actor, 'status_changed', $campaign, $oldValues, ['status' => $newStatus]);
        });
    }

    public function updateBudget(Campaign $campaign, float $budget, float $spend, User $actor): void
    {
        DB::transaction(function () use ($campaign, $budget, $spend, $actor) {
            $oldValues = [
                'budget' => $campaign->budget,
                'spend' => $campaign->spend
            ];

            $campaign->update([
                'budget' => $budget,
                'spend' => $spend,
                'updated_by' => $actor->id
            ]);

            $this->logActivity($actor, 'budget_updated', $campaign, $oldValues, [
                'budget' => $budget,
                'spend' => $spend
            ]);

            // Check budget percentages
            if ($budget > 0) {
                $percent = ($spend / $budget) * 100;
                if ($percent >= 100) {
                    $this->triggerNotification("Critical: Spend limits for '{$campaign->name}' exceeded (100%+ spent).", $actor);
                } elseif ($percent >= 80) {
                    $this->triggerNotification("Warning: Spend limits for '{$campaign->name}' exceeded 80%.", $actor);
                }
            }
        });
    }

    public function logActivity(User $actor, string $action, Campaign $campaign, ?array $old = null, ?array $new = null): void
    {
    }

    private function triggerNotification(string $message, User $actor): void
    {
    }
}
