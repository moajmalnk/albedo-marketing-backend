<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\ChallengeComment;
use App\Models\MarketingChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketingChallengeController extends Controller
{
    private const STATUSES = ['Open', 'Assigned', 'In Progress', 'Blocked', 'Resolved', 'Closed', 'Cancelled'];
    private const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    private function reporterDisplayName(User $user): string
    {
        $n = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $n !== '' ? $n : ($user->email ?? 'Unknown');
    }

    /** Resolve user department string to an active department name used on challenges. */
    private function normalizeDepartment(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $t = trim($raw);
        $active = Department::query()->where('status', 'active');
        $byName = (clone $active)->where('name', $t)->value('name');
        if ($byName !== null) {
            return $byName;
        }
        $byCode = (clone $active)->whereRaw('UPPER(code) = ?', [strtoupper($t)])->value('name');
        if ($byCode !== null) {
            return $byCode;
        }

        return match (strtoupper($t)) {
            'PM' => (clone $active)->whereRaw('UPPER(code) = ?', ['PM'])->value('name') ?? 'Performance Marketing',
            'IM' => (clone $active)->whereRaw('UPPER(code) = ?', ['IM'])->value('name') ?? 'Influence Marketing',
            default => $t,
        };
    }

    private function userDepartmentConstraint(User $user): ?string
    {
        $user->loadMissing('role');
        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return null;
        }

        return $this->normalizeDepartment($user->department);
    }

    private function checkPolicy(string $ability, MarketingChallenge $challenge): void
    {
        $policy = new \App\Policies\ChallengePolicy();
        $actor = request()->user();
        if (! $actor) {
            abort(401);
        }

        if (! $policy->{$ability}($actor, $challenge)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $user = $request->user()->loadMissing('role');
        $query = MarketingChallenge::with(['assignee', 'assigner', 'creator', 'comments.user'])->orderByDesc('date_reported')->orderByDesc('id');

        $scoped = $this->userDepartmentConstraint($user);
        if ($scoped !== null) {
            $query->where('department', $scoped);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $user = $request->user()->loadMissing('role');

        $data = $request->validate([
            'category' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string', 'max:20000'],
            'department' => [
                'required',
                'string',
                'max:64',
                Rule::exists('departments', 'name')->where(fn ($q) => $q->where('status', 'active')),
            ],
            'reported_by' => ['nullable', 'string', 'max:120'],
            'affected_leads' => ['nullable', 'array', 'max:500'],
            'affected_leads.*' => ['string', 'max:64'],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],

            'priority' => ['nullable', 'string', Rule::in(self::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],

            'date_reported' => ['nullable', 'date'],
            'date_resolved' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $scoped = $this->userDepartmentConstraint($user);
        if ($scoped !== null && $data['department'] !== $scoped) {
            abort(422, 'You can only log challenges for your department.');
        }

        $row = MarketingChallenge::query()->create([
            'category' => $data['category'],
            'description' => $data['description'],
            'department' => $data['department'],
            'reported_by' => $data['reported_by'] ?? $this->reporterDisplayName($user),
            'affected_leads' => $data['affected_leads'] ?? [],
            'status' => $data['status'] ?? 'Open',

            'priority' => $data['priority'] ?? 'Medium',
            'assigned_to' => $data['assigned_to'] ?? null,
            'assigned_by' => isset($data['assigned_to']) ? $user->id : null,
            'assigned_at' => isset($data['assigned_to']) ? now() : null,

            'date_reported' => $data['date_reported'] ?? now()->toDateString(),
            'date_resolved' => $data['date_resolved'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        if ($row->status === 'Resolved' && ! $row->date_resolved) {
            $row->date_resolved = now()->toDateString();
            $row->save();
        }

        
        ChallengeComment::create([
            'challenge_id' => $row->id,
            'user_id' => $user->id,
            'type' => 'system_event',
            'content' => 'Challenge created.'
        ]);
        if ($row->assigned_to) {
            ChallengeComment::create([
                'challenge_id' => $row->id,
                'user_id' => $user->id,
                'type' => 'system_event',
                'content' => 'Assigned challenge.'
            ]);
        }
        $row->load(['assignee', 'assigner', 'creator', 'comments.user']);

        return response()->json($row, 201);
    }

    public function show(Request $request, MarketingChallenge $marketing_challenge)
    {
        $this->checkPolicy('view', $marketing_challenge);

        $marketing_challenge->load(['assignee', 'assigner', 'creator', 'comments.user']);
        return response()->json($marketing_challenge);
    }

    public function update(Request $request, MarketingChallenge $marketing_challenge)
    {
        $user = $request->user();
        $this->checkPolicy('update', $marketing_challenge);

        $data = $request->validate([
            'category' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['sometimes', 'required', 'string', 'max:20000'],
            'department' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::exists('departments', 'name')->where(fn ($q) => $q->where('status', 'active')),
            ],
            'reported_by' => ['sometimes', 'nullable', 'string', 'max:120'],
            'affected_leads' => ['sometimes', 'nullable', 'array', 'max:500'],
            'affected_leads.*' => ['string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],

            'priority' => ['sometimes', 'string', Rule::in(self::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],

            'date_reported' => ['sometimes', 'date'],
            'date_resolved' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $scoped = $this->userDepartmentConstraint($user);
        if ($scoped !== null && isset($data['department']) && $data['department'] !== $scoped) {
            abort(422, 'You cannot move this challenge to another department.');
        }

        
        $oldStatus = $marketing_challenge->status;
        $oldPriority = $marketing_challenge->priority;
        $oldAssignee = $marketing_challenge->assigned_to;

        $marketing_challenge->fill($data);
        
        if (array_key_exists('assigned_to', $data) && $oldAssignee !== $data['assigned_to']) {
            $marketing_challenge->assigned_by = $data['assigned_to'] ? $user->id : null;
            $marketing_challenge->assigned_at = $data['assigned_to'] ? now() : null;
            if ($marketing_challenge->status === 'Open' && $data['assigned_to']) {
                $marketing_challenge->status = 'Assigned';
            }
        }

        if ($marketing_challenge->status === 'Resolved' && ! $marketing_challenge->date_resolved) {
            $marketing_challenge->date_resolved = now()->toDateString();
        }
        if ($marketing_challenge->status !== 'Resolved') {
            $marketing_challenge->date_resolved = null;
        }
        $marketing_challenge->save();

        if ($oldStatus !== $marketing_challenge->status) {
            ChallengeComment::create([
                'challenge_id' => $marketing_challenge->id,
                'user_id' => $user->id,
                'type' => 'system_event',
                'content' => "Status changed from {$oldStatus} to {$marketing_challenge->status}."
            ]);
        }
        if ($oldPriority !== $marketing_challenge->priority) {
            ChallengeComment::create([
                'challenge_id' => $marketing_challenge->id,
                'user_id' => $user->id,
                'type' => 'system_event',
                'content' => "Priority changed from {$oldPriority} to {$marketing_challenge->priority}."
            ]);
        }
        if (array_key_exists('assigned_to', $data) && $oldAssignee !== $data['assigned_to']) {
            ChallengeComment::create([
                'challenge_id' => $marketing_challenge->id,
                'user_id' => $user->id,
                'type' => 'system_event',
                'content' => $data['assigned_to'] ? 'Challenge reassigned.' : 'Challenge unassigned.'
            ]);
        }

        $marketing_challenge->load(['assignee', 'assigner', 'creator', 'comments.user']);
        $marketing_challenge->load(['assignee', 'assigner', 'creator', 'comments.user']);
        return response()->json($marketing_challenge);

    }

    public function destroy(Request $request, MarketingChallenge $marketing_challenge)
    {
        $this->checkPolicy('delete', $marketing_challenge);

        $marketing_challenge->delete();

        return response()->json(['message' => 'Challenge deleted']);
    }

    public function addComment(Request $request, MarketingChallenge $marketing_challenge)
    {
        $user = $request->user();
        
        // Ensure user can view/update before commenting
        $this->checkPolicy('view', $marketing_challenge);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000']
        ]);

        $comment = ChallengeComment::create([
            'challenge_id' => $marketing_challenge->id,
            'user_id' => $user->id,
            'type' => 'comment',
            'content' => $data['content']
        ]);

        $marketing_challenge->load(['assignee', 'assigner', 'creator', 'comments.user']);
        
        // Load the new comment's user for immediate response if needed, 
        // but returning the whole challenge is usually easier for frontend replacement
        return response()->json($marketing_challenge);
    }
}
