<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\UnknownCall;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CallLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        $perPage = min(max((int) $request->input('limit', 50), 1), 100);
        $page = max((int) $request->input('page', 1), 1);
        $unknownOnly = filter_var($request->input('unknown_only'), FILTER_VALIDATE_BOOLEAN);
        $q = trim((string) $request->input('q', ''));
        $direction = $request->input('direction');
        $agentId = $request->filled('agent_id') ? (int) $request->input('agent_id') : null;
        $from = $request->input('from');
        $to = $request->input('to');

        $rows = collect();

        if (! $unknownOnly) {
            $activityQuery = LeadActivity::query()
                ->with([
                    'user:id,first_name,last_name,phone_extension',
                    'lead:id,student_name,phone,owner_id',
                ])
                ->where('type', 'call');

            $this->scopeActivities($activityQuery, $user, $roleKey);

            if ($q !== '') {
                $activityQuery->where(function ($query) use ($q) {
                    $query->whereHas('lead', function ($leadQuery) use ($q) {
                        $leadQuery->where('phone', 'like', "%{$q}%")
                            ->orWhere('student_name', 'like', "%{$q}%");
                    });
                });
            }

            if (in_array($direction, ['inbound', 'outbound'], true)) {
                $activityQuery->where('direction', $direction);
            }

            if ($agentId) {
                $activityQuery->where('user_id', $agentId);
            }

            if ($from) {
                $activityQuery->whereDate('occurred_at', '>=', $from);
            }
            if ($to) {
                $activityQuery->whereDate('occurred_at', '<=', $to);
            }

            $rows = $rows->concat(
                $activityQuery->latest('occurred_at')->limit(500)->get()->map(fn (LeadActivity $activity) => $this->mapActivity($activity))
            );
        }

        $unknownQuery = UnknownCall::query()->where('status', 'open');
        $this->scopeUnknowns($unknownQuery, $user, $roleKey);

        if ($q !== '') {
            $unknownQuery->where('from_phone', 'like', "%{$q}%");
        }

        if (in_array($direction, ['inbound', 'outbound'], true)) {
            $unknownQuery->where('direction', $direction);
        }

        if ($agentId) {
            $agent = User::query()->find($agentId);
            if ($agent?->phone_extension) {
                $unknownQuery->where('agent_extension', $agent->phone_extension);
            } else {
                $unknownQuery->whereRaw('1 = 0');
            }
        }

        if ($from) {
            $unknownQuery->whereDate('started_at', '>=', $from);
        }
        if ($to) {
            $unknownQuery->whereDate('started_at', '<=', $to);
        }

        $agentExtMap = $this->agentExtensionMap(
            $unknownQuery->clone()->whereNotNull('agent_extension')->distinct()->pluck('agent_extension')->all()
        );

        $rows = $rows->concat(
            $unknownQuery->latest('started_at')->limit(500)->get()->map(
                fn (UnknownCall $call) => $this->mapUnknown($call, $agentExtMap)
            )
        );

        $sorted = $rows
            ->sortByDesc(fn (array $row) => $row['occurred_at'] ?? '')
            ->values();

        $total = $sorted->count();
        $pageItems = $sorted->forPage($page, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginator);
    }

    public function link(Request $request, UnknownCall $unknownCall)
    {
        if ($unknownCall->status !== 'open') {
            return response()->json(['message' => 'This unknown call is already resolved.'], 422);
        }

        $data = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
        ]);

        $lead = Lead::query()->findOrFail($data['lead_id']);
        $user = $request->user();

        $activity = LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'type' => 'call',
            'direction' => $unknownCall->direction,
            'connected' => ($unknownCall->disposition ?? '') === 'answered',
            'duration_sec' => $unknownCall->duration_sec,
            'recording_url' => $unknownCall->recording_url,
            'comments' => 'Linked from unknown call',
            'occurred_at' => $unknownCall->started_at ?? now(),
            'payload' => [
                'unknown_call_id' => $unknownCall->id,
                'call_id' => $unknownCall->call_id,
                'disposition' => $unknownCall->disposition,
            ],
        ]);

        $unknownCall->update([
            'status' => 'linked',
            'linked_lead_id' => $lead->id,
            'linked_by_user_id' => $user->id,
            'resolved_at' => now(),
        ]);

        $activity->load([
            'user:id,first_name,last_name,phone_extension',
            'lead:id,student_name,phone,owner_id',
        ]);

        return response()->json($this->mapActivity($activity));
    }

    public function ignore(Request $request, UnknownCall $unknownCall)
    {
        if ($unknownCall->status !== 'open') {
            return response()->json(['message' => 'This unknown call is already resolved.'], 422);
        }

        $unknownCall->update([
            'status' => 'ignored',
            'linked_by_user_id' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json(['status' => 'ignored']);
    }

    private function scopeActivities($query, User $user, string $roleKey): void
    {
        if (in_array($roleKey, ['telecaller', 'psa', 'advisor'], true)) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('lead', fn ($leadQuery) => $leadQuery->where('owner_id', $user->id));
            });
        }
    }

    private function scopeUnknowns($query, User $user, string $roleKey): void
    {
        if (in_array($roleKey, ['telecaller', 'psa', 'advisor'], true)) {
            if ($user->phone_extension) {
                $query->where('agent_extension', $user->phone_extension);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
    }

    private function agentExtensionMap(array $extensions): array
    {
        if ($extensions === []) {
            return [];
        }

        return User::query()
            ->whereIn('phone_extension', $extensions)
            ->get(['id', 'first_name', 'last_name', 'phone_extension'])
            ->keyBy('phone_extension')
            ->all();
    }

    private function mapActivity(LeadActivity $activity): array
    {
        $user = $activity->user;
        $lead = $activity->lead;

        return [
            'id' => 'act-'.$activity->id,
            'source' => 'lead_activity',
            'phone' => $lead?->phone,
            'direction' => $activity->direction,
            'duration_sec' => $activity->duration_sec ?? 0,
            'occurred_at' => optional($activity->occurred_at)?->toIso8601String(),
            'agent_name' => $this->formatUserName($user),
            'agent_id' => $activity->user_id,
            'contact_name' => $lead?->student_name,
            'lead_id' => $activity->lead_id,
            'outcome' => $activity->outcome,
            'notes' => $activity->comments,
            'recording_url' => $activity->recording_url,
            'status' => 'linked',
            'disposition' => null,
        ];
    }

    private function mapUnknown(UnknownCall $call, array $agentExtMap): array
    {
        $agent = $call->agent_extension ? ($agentExtMap[$call->agent_extension] ?? null) : null;

        return [
            'id' => 'unk-'.$call->id,
            'source' => 'unknown_call',
            'phone' => $call->from_phone,
            'direction' => $call->direction,
            'duration_sec' => $call->duration_sec ?? 0,
            'occurred_at' => optional($call->started_at ?? $call->created_at)?->toIso8601String(),
            'agent_name' => $agent ? $this->formatUserName($agent) : ($call->agent_extension ?: 'Unknown'),
            'agent_id' => $agent?->id,
            'contact_name' => null,
            'lead_id' => null,
            'outcome' => null,
            'notes' => null,
            'recording_url' => $call->recording_url,
            'status' => 'unlinked',
            'disposition' => $call->disposition,
        ];
    }

    private function formatUserName(?User $user): string
    {
        if (! $user) {
            return 'Unknown';
        }

        return trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: 'Unknown';
    }
}
