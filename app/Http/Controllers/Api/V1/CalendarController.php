<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Lead;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CalendarController extends Controller
{
    private const TEAM_VIEW_ROLES = ['sales_head', 'admin', 'super_admin'];

    private function actorRoleKey(Request $request): string
    {
        return $request->user()?->role?->key ?? '';
    }

    private function displayName(?object $user): ?string
    {
        if (! $user) {
            return null;
        }
        $last = property_exists($user, 'last_name') && $user->last_name
            ? ' '.$user->last_name
            : '';

        return trim(($user->first_name ?? '').$last) ?: null;
    }

    public function events(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'owner_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['task', 'assessment', 'followup'])],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        $typeFilter = $data['type'] ?? null;
        $statusFilter = isset($data['status']) ? strtolower((string) $data['status']) : null;

        $canViewTeam = in_array($this->actorRoleKey($request), self::TEAM_VIEW_ROLES, true);
        $ownerId = $request->filled('owner_id') ? (int) $request->input('owner_id') : null;

        // PSA / Advisor (and other non-team roles) are always scoped to themselves.
        if (! $canViewTeam) {
            $ownerId = (int) $request->user()?->id;
            if ($ownerId <= 0) {
                return response()->json(['data' => []]);
            }
        }

        $events = [];

        if ($typeFilter === null || $typeFilter === 'task') {
            $taskQuery = Task::query()
                ->whereBetween('due_at', [$from, $to])
                ->when($ownerId !== null, fn ($q) => $q->where('assigned_to', $ownerId))
                ->when($statusFilter !== null, fn ($q) => $q->where('status', $statusFilter))
                ->with([
                    'lead:id,student_name',
                    'assignee:id,first_name,last_name',
                ])
                ->orderBy('due_at');

            foreach ($taskQuery->get() as $task) {
                $events[] = [
                    'id' => 'task-'.$task->id,
                    'source_id' => $task->id,
                    'type' => 'task',
                    'title' => $task->title,
                    'starts_at' => optional($task->due_at)->toIso8601String(),
                    'ends_at' => optional($task->due_at)?->copy()->addMinutes(30)->toIso8601String(),
                    'lead_id' => $task->lead_id,
                    'lead_name' => optional($task->lead)->student_name,
                    'status' => $task->status,
                    'owner_id' => $task->assigned_to,
                    'owner_name' => $this->displayName($task->assignee),
                ];
            }
        }

        if ($typeFilter === null || $typeFilter === 'assessment') {
            $assessmentQuery = Assessment::query()
                ->whereBetween('scheduled_at', [$from, $to])
                ->when($ownerId !== null, function ($q) use ($ownerId) {
                    $q->whereHas('lead', function ($lq) use ($ownerId) {
                        $lq->where(function ($ownerQ) use ($ownerId) {
                            $ownerQ->where('owner_id', $ownerId)
                                ->orWhere('psa_owner_id', $ownerId)
                                ->orWhere('advisor_owner_id', $ownerId);
                        });
                    });
                })
                ->when($statusFilter !== null, fn ($q) => $q->where('status', $statusFilter))
                ->with([
                    'lead:id,student_name,owner_id,psa_owner_id,advisor_owner_id',
                    'lead.owner:id,first_name,last_name',
                ])
                ->orderBy('scheduled_at');

            foreach ($assessmentQuery->get() as $assessment) {
                $lead = $assessment->lead;
                $events[] = [
                    'id' => 'assessment-'.$assessment->id,
                    'source_id' => $assessment->id,
                    'type' => 'assessment',
                    'title' => 'Assessment · '.($lead?->student_name ?: 'Lead #'.$assessment->lead_id),
                    'starts_at' => optional($assessment->scheduled_at)->toIso8601String(),
                    'ends_at' => optional($assessment->scheduled_at)?->copy()->addMinutes(45)->toIso8601String(),
                    'lead_id' => $assessment->lead_id,
                    'lead_name' => $lead?->student_name,
                    'status' => $assessment->status,
                    'owner_id' => $lead?->owner_id,
                    'owner_name' => $this->displayName($lead?->owner),
                ];
            }
        }

        if ($typeFilter === null || $typeFilter === 'followup') {
            $followupQuery = Lead::query()
                ->whereNotNull('next_action_at')
                ->whereBetween('next_action_at', [$from, $to])
                ->when($ownerId !== null, function ($q) use ($ownerId) {
                    $q->where(function ($ownerQ) use ($ownerId) {
                        $ownerQ->where('owner_id', $ownerId)
                            ->orWhere('psa_owner_id', $ownerId)
                            ->orWhere('advisor_owner_id', $ownerId);
                    });
                })
                ->with(['owner:id,first_name,last_name'])
                ->orderBy('next_action_at');

            foreach ($followupQuery->get() as $lead) {
                // Treat "open" as any non-completed follow-up; leads don't store follow-up status.
                if ($statusFilter === 'completed') {
                    continue;
                }
                $events[] = [
                    'id' => 'followup-'.$lead->id,
                    'source_id' => $lead->id,
                    'type' => 'followup',
                    'title' => 'Follow-up · '.($lead->student_name ?: 'Lead #'.$lead->id),
                    'starts_at' => optional($lead->next_action_at)->toIso8601String(),
                    'ends_at' => optional($lead->next_action_at)?->copy()->addMinutes(30)->toIso8601String(),
                    'lead_id' => $lead->id,
                    'lead_name' => $lead->student_name,
                    'status' => 'pending',
                    'owner_id' => $lead->owner_id,
                    'owner_name' => $this->displayName($lead->owner),
                ];
            }
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['starts_at'], (string) $b['starts_at']));

        return response()->json(['data' => $events]);
    }
}
