<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChallengeCategory;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChallengeCategoryController extends Controller
{
    /** @return list<string> */
    private function allowedChallengeCategoryDepartments(): array
    {
        $names = Department::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return array_values(array_unique(array_merge(['Both'], $names)));
    }

    private function ensureSettingsAdmin(Request $request): void
    {
        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        if (! in_array($roleKey, ['super_admin', 'admin', 'dept_head'], true)) {
            abort(403, 'You are not authorized to manage challenge categories.');
        }
    }

    public function index(Request $request)
    {
        $request->user()?->loadMissing('role');

        $rows = ChallengeCategory::query()
            ->orderBy('name')
            ->orderBy('department')
            ->get();

        return response()->json($rows);
    }

    public function store(Request $request)
    {
        $this->ensureSettingsAdmin($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('challenge_categories', 'name')->where(
                    fn ($q) => $q->where('department', $request->input('department'))
                ),
            ],
            'department' => ['required', 'string', 'max:64', Rule::in($this->allowedChallengeCategoryDepartments())],
            'status' => ['nullable', 'string', Rule::in(['Active', 'Deactivated'])],
        ]);

        $row = ChallengeCategory::query()->create([
            'name' => $data['name'],
            'department' => $data['department'],
            'status' => $data['status'] ?? 'Active',
        ]);

        return response()->json($row, 201);
    }

    public function update(Request $request, ChallengeCategory $challenge_category)
    {
        $this->ensureSettingsAdmin($request);

        $deptForUnique = $request->input('department', $challenge_category->department);

        $data = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:191',
                Rule::unique('challenge_categories', 'name')
                    ->where(fn ($q) => $q->where('department', $deptForUnique))
                    ->ignore($challenge_category->id),
            ],
            'department' => ['sometimes', 'required', 'string', 'max:64', Rule::in($this->allowedChallengeCategoryDepartments())],
            'status' => ['sometimes', 'string', Rule::in(['Active', 'Deactivated'])],
        ]);

        $challenge_category->update($data);

        return response()->json($challenge_category->fresh());
    }

    public function destroy(Request $request, ChallengeCategory $challenge_category)
    {
        $this->ensureSettingsAdmin($request);

        $challenge_category->delete();

        return response()->json(['message' => 'Challenge category deleted']);
    }
}
