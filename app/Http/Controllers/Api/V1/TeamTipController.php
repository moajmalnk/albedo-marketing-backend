<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeamTip;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamTipController extends Controller
{
    private function ensureCanManageTips(Request $request): void
    {
        $user = $request->user()?->loadMissing('role');
        $key = $user?->role?->key;

        if (! in_array($key, ['super_admin', 'admin', 'dept_head', 'department_head'], true)) {
            abort(403, 'You are not authorized to manage team tips.');
        }
    }

    private function senderDisplayName(User $user): string
    {
        $n = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $n !== '' ? $n : ($user->email ?? 'Unknown');
    }

    private function senderRoleLabel(User $user): ?string
    {
        return $user->role?->name;
    }

    public function index(Request $request)
    {
        $this->ensureCanManageTips($request);

        $query = TeamTip::query()->orderByDesc('date_sent')->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('date_sent', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('date_sent', '<=', $request->query('to'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->ensureCanManageTips($request);

        $user = $request->user()->loadMissing('role');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string', 'max:20000'],
            'sent_to' => ['required', 'array', 'min:1', 'max:200'],
            'sent_to.*' => ['string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['Active', 'Inactive'])],
            'priority' => ['nullable', 'string', Rule::in(['Normal', 'High'])],
            'read_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'date_sent' => ['nullable', 'date'],
        ]);

        $row = TeamTip::query()->create([
            'title' => $data['title'],
            'description' => $data['description'],
            'sent_to' => $data['sent_to'],
            'sent_by' => $this->senderDisplayName($user),
            'sent_by_role' => $this->senderRoleLabel($user),
            'date_sent' => $data['date_sent'] ?? now()->toDateString(),
            'status' => $data['status'] ?? 'Active',
            'priority' => $data['priority'] ?? null,
            'read_count' => $data['read_count'] ?? 0,
            'created_by' => $user->id,
        ]);

        return response()->json($row->fresh(), 201);
    }

    public function show(Request $request, TeamTip $team_tip)
    {
        $this->ensureCanManageTips($request);

        return response()->json($team_tip);
    }

    public function update(Request $request, TeamTip $team_tip)
    {
        $this->ensureCanManageTips($request);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['sometimes', 'required', 'string', 'max:20000'],
            'sent_to' => ['sometimes', 'required', 'array', 'min:1', 'max:200'],
            'sent_to.*' => ['string', 'max:120'],
            'status' => ['sometimes', 'string', Rule::in(['Active', 'Inactive'])],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(['Normal', 'High'])],
            'read_count' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'date_sent' => ['sometimes', 'date'],
        ]);

        $team_tip->update($data);

        return response()->json($team_tip->fresh());
    }

    public function destroy(Request $request, TeamTip $team_tip)
    {
        $this->ensureCanManageTips($request);

        $team_tip->delete();

        return response()->json(['message' => 'Tip deleted']);
    }
}
