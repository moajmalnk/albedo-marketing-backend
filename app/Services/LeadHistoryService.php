<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStage;
use App\Models\LeadStageTransition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds a unified CRM timeline for a lead: audit rows (record + pipeline),
 * and telecaller/sales activities (calls, notes, follow-ups, etc.).
 */
class LeadHistoryService
{
    /**
     * @return array{lead_id: int, items: list<array<string, mixed>>}
     */
    public function timeline(Lead $lead, int $perSourceLimit = 250, int $mergeLimit = 500): array
    {
        $leadId = $lead->id;
        $transitionIds = LeadStageTransition::query()->where('lead_id', $leadId)->pluck('id');

        $stageLabels = LeadStage::query()->pluck('label', 'id');

        $audits = AuditLog::query()
            ->with(['actor.role'])
            ->where(function ($q) use ($leadId, $transitionIds): void {
                $q->where(fn ($q2) => $q2->where('entity_type', 'lead')->where('entity_id', $leadId));
                if ($transitionIds->isNotEmpty()) {
                    $q->orWhere(fn ($q2) => $q2->where('entity_type', 'lead_stage_transition')->whereIn('entity_id', $transitionIds));
                }
            })
            ->orderByDesc('created_at')
            ->limit($perSourceLimit)
            ->get();

        $activities = LeadActivity::query()
            ->with(['user.role'])
            ->where('lead_id', $leadId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($perSourceLimit)
            ->get();

        $rows = collect();

        foreach ($audits as $log) {
            $rows->push($this->formatAuditRow($log, $stageLabels));
        }
        foreach ($activities as $activity) {
            $rows->push($this->formatActivityRow($activity));
        }

        $sorted = $rows
            ->sortByDesc(fn (array $r) => $r['occurred_at'])
            ->values()
            ->take($mergeLimit)
            ->all();

        return ['lead_id' => $leadId, 'items' => $sorted];
    }

    /**
     * @param  Collection<int, string|null>  $stageLabels
     * @return array<string, mixed>
     */
    private function formatAuditRow(AuditLog $log, Collection $stageLabels): array
    {
        $occurredAt = $log->created_at instanceof Carbon
            ? $log->created_at->toIso8601String()
            : (string) $log->created_at;

        $actor = $this->serializeActor($log->actor);
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];

        [$title, $description] = $this->describeAudit($log->action, $old, $new, $stageLabels);

        return [
            'id' => 'audit-'.$log->id,
            'kind' => 'audit',
            'occurred_at' => $occurredAt,
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'title' => $title,
            'description' => $description,
            'actor' => $actor,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'old_values' => $old,
            'new_values' => $new,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatActivityRow(LeadActivity $activity): array
    {
        $occurredAt = $activity->occurred_at instanceof Carbon
            ? $activity->occurred_at->toIso8601String()
            : (string) $activity->occurred_at;

        $parts = array_filter([
            $activity->outcome,
            $activity->comments,
            $activity->direction ? ucfirst((string) $activity->direction) : null,
            $activity->duration_sec !== null ? $activity->duration_sec.'s' : null,
        ]);

        return [
            'id' => 'activity-'.$activity->id,
            'kind' => 'activity',
            'occurred_at' => $occurredAt,
            'action' => 'lead.activity.'.$activity->type,
            'title' => ucfirst(str_replace('_', ' ', (string) $activity->type)),
            'description' => implode(' · ', $parts) ?: '—',
            'actor' => $this->serializeActor($activity->user),
            'activity_type' => $activity->type,
            'outcome' => $activity->outcome,
            'comments' => $activity->comments,
            'payload' => $activity->payload,
        ];
    }

    /**
     * @return array{id: int|null, name: string, email: string|null, role: array{key: string|null, name: string|null}|null}
     */
    private function serializeActor(?\App\Models\User $user): array
    {
        if ($user === null) {
            return [
                'id' => null,
                'name' => 'System',
                'email' => null,
                'role' => null,
            ];
        }

        $name = trim(implode(' ', array_filter([$user->first_name, $user->last_name]))) ?: $user->email;

        return [
            'id' => $user->id,
            'name' => $name,
            'email' => $user->email,
            'role' => $user->relationLoaded('role') && $user->role
                ? ['key' => $user->role->key, 'name' => $user->role->name]
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  Collection<int, string|null>  $stageLabels
     * @return array{0: string, 1: string}
     */
    private function describeAudit(string $action, array $old, array $new, Collection $stageLabels): array
    {
        return match ($action) {
            'lead.created' => ['Lead created', 'New lead record added in CRM.'],
            'lead.deleted' => ['Lead deleted', 'Lead record removed (soft delete).'],
            'lead.stage_change' => $this->describeStageTransitionAudit($new, $stageLabels),
            'lead.updated' => $this->describeLeadUpdated($old, $new, $stageLabels),
            default => [
                str_replace(['.', '_'], [' · ', ' '], $action),
                $this->summarizeScalarMap($new) ?: $this->summarizeScalarMap($old) ?: '—',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  Collection<int, string|null>  $stageLabels
     * @return array{0: string, 1: string}
     */
    private function describeStageTransitionAudit(array $new, Collection $stageLabels): array
    {
        $fromId = isset($new['from_stage_id']) ? (int) $new['from_stage_id'] : null;
        $toId = isset($new['to_stage_id']) ? (int) $new['to_stage_id'] : null;
        $from = $fromId ? (string) ($stageLabels[$fromId] ?? '#'.$fromId) : '—';
        $to = $toId ? (string) ($stageLabels[$toId] ?? '#'.$toId) : '—';
        $reason = isset($new['reason']) && $new['reason'] !== '' && $new['reason'] !== null
            ? ' Reason: '.(string) $new['reason']
            : '';

        return ['Pipeline stage changed', "{$from} → {$to}.{$reason}"];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  Collection<int, string|null>  $stageLabels
     * @return array{0: string, 1: string}
     */
    private function describeLeadUpdated(array $old, array $new, Collection $stageLabels): array
    {
        $keys = array_keys($new);
        sort($keys);
        $readable = [];
        foreach ($keys as $key) {
            if ($key === 'updated_at') {
                continue;
            }
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;
            if ($key === 'stage_id') {
                $b = $before !== null ? (string) ($stageLabels[(int) $before] ?? '#'.$before) : '—';
                $a = $after !== null ? (string) ($stageLabels[(int) $after] ?? '#'.$after) : '—';
                $readable[] = "stage: {$b} → {$a}";
            } elseif ($key === 'owner_id') {
                $readable[] = 'assignee (owner_id): '.($before ?? '—').' → '.($after ?? '—');
            } elseif ($key === 'notes_html') {
                $readable[] = 'notes updated';
            } else {
                $readable[] = $key.': '.$this->jsonBrief($before).' → '.$this->jsonBrief($after);
            }
        }

        $summary = $readable === [] ? 'Record touched (no scalar diff captured).' : implode('; ', $readable);

        return ['Lead record updated', $summary];
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function summarizeScalarMap(array $map): string
    {
        $keys = array_keys($map);
        if ($keys === []) {
            return '';
        }
        sort($keys);

        return 'Keys: '.implode(', ', $keys);
    }

    private function jsonBrief(mixed $v): string
    {
        $s = json_encode($v, JSON_UNESCAPED_UNICODE);
        if ($s === false) {
            return '?';
        }

        return strlen($s) > 140 ? substr($s, 0, 137).'…' : $s;
    }
}
