<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\MarketingChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketingChallengeController extends Controller
{
    private const STATUSES = ['Open', 'In Progress', 'Resolved'];

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
        $key = $user->role?->key;
        if (! in_array($key, ['dept_head', 'department_head'], true)) {
            return null;
        }

        return $this->normalizeDepartment($user->department);
    }

    private function ensureDeptHeadOwnsChallenge(User $user, MarketingChallenge $row): void
    {
        $allowed = $this->userDepartmentConstraint($user);
        if ($allowed === null) {
            return;
        }
        if ($row->department !== $allowed) {
            abort(403, 'You can only manage challenges for your department.');
        }
    }

    public function index(Request $request)
    {
        $user = $request->user()->loadMissing('role');
        $query = MarketingChallenge::query()->orderByDesc('date_reported')->orderByDesc('id');

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
            'date_reported' => $data['date_reported'] ?? now()->toDateString(),
            'date_resolved' => $data['date_resolved'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        if ($row->status === 'Resolved' && ! $row->date_resolved) {
            $row->date_resolved = now()->toDateString();
            $row->save();
        }

        return response()->json($row->fresh(), 201);
    }

    public function show(Request $request, MarketingChallenge $marketing_challenge)
    {
        $this->ensureDeptHeadOwnsChallenge($request->user(), $marketing_challenge);

        return response()->json($marketing_challenge);
    }

    public function update(Request $request, MarketingChallenge $marketing_challenge)
    {
        $user = $request->user();
        $this->ensureDeptHeadOwnsChallenge($user, $marketing_challenge);

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
            'date_reported' => ['sometimes', 'date'],
            'date_resolved' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $scoped = $this->userDepartmentConstraint($user);
        if ($scoped !== null && isset($data['department']) && $data['department'] !== $scoped) {
            abort(422, 'You cannot move this challenge to another department.');
        }

        $marketing_challenge->fill($data);
        if ($marketing_challenge->status === 'Resolved' && ! $marketing_challenge->date_resolved) {
            $marketing_challenge->date_resolved = now()->toDateString();
        }
        if ($marketing_challenge->status !== 'Resolved') {
            $marketing_challenge->date_resolved = null;
        }
        $marketing_challenge->save();

        return response()->json($marketing_challenge->fresh());
    }

    public function destroy(Request $request, MarketingChallenge $marketing_challenge)
    {
        $this->ensureDeptHeadOwnsChallenge($request->user(), $marketing_challenge);

        $marketing_challenge->delete();

        return response()->json(['message' => 'Challenge deleted']);
    }
}
