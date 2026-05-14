<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    private function ensureSettingsAdmin(Request $request): void
    {
        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        if (! in_array($roleKey, ['super_admin', 'admin'], true)) {
            abort(403, 'You are not authorized to manage departments.');
        }
    }

    private function ensureCanListDepartments(Request $request): void
    {
        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        if (! in_array($roleKey, ['super_admin', 'admin', 'dept_head'], true)) {
            abort(403, 'You are not authorized to view departments.');
        }
    }

    public function index(Request $request)
    {
        $this->ensureCanListDepartments($request);

        $departments = Department::query()
            ->withCount('users')
            ->orderBy('name')
            ->get();

        $activeCount = $departments->where('status', 'active')->count();
        $total = $departments->count();
        $largest = $departments->sortByDesc('users_count')->first();

        return response()->json([
            'data' => $departments,
            'stats' => [
                'total' => $total,
                'active_count' => $activeCount,
                'inactive_count' => $total - $activeCount,
                'largest' => $largest ? [
                    'id' => $largest->id,
                    'name' => $largest->name,
                    'users_count' => $largest->users_count,
                ] : null,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureSettingsAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:departments,name'],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/', 'unique:departments,code'],
        ]);

        $code = isset($data['code']) ? strtoupper($data['code']) : $this->generateUniqueCode($data['name']);

        $department = Department::query()->create([
            'code' => $code,
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json($department->loadCount('users'), 201);
    }

    private function generateUniqueCode(string $name): string
    {
        $base = Str::upper(Str::slug($name, '_'));
        $base = preg_replace('/[^A-Z0-9_]/', '', $base) ?: 'DEPT';
        $base = Str::limit($base, 28, '');
        $code = $base;
        $n = 0;
        while (Department::query()->where('code', $code)->exists()) {
            $n++;
            $suffix = '_'.$n;
            $code = Str::limit($base, 32 - strlen($suffix), '').$suffix;
        }

        return $code;
    }

    public function show(Request $request, Department $department)
    {
        $this->ensureCanListDepartments($request);

        return response()->json($department->loadCount('users'));
    }

    public function update(Request $request, Department $department)
    {
        $this->ensureSettingsAdmin($request);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($department->id)],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'code' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('departments', 'code')->ignore($department->id)],
        ]);

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $department->update($data);

        return response()->json($department->fresh()->loadCount('users'));
    }

    public function destroy(Request $request, Department $department)
    {
        $this->ensureSettingsAdmin($request);

        if ($department->users()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a department that has users assigned. Remove users or deactivate the department instead.',
            ], 409);
        }

        $department->delete();

        return response()->json(['message' => 'Department deleted']);
    }

    public function bulkDestroy(Request $request)
    {
        $this->ensureSettingsAdmin($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'exists:departments,id'],
        ]);

        $deleted = [];
        $failed = [];

        foreach ($data['ids'] as $id) {
            $department = Department::query()->find($id);
            if (! $department) {
                continue;
            }
            if ($department->users()->exists()) {
                $failed[] = ['id' => (int) $id, 'reason' => 'Department has users assigned.'];

                continue;
            }
            $department->delete();
            $deleted[] = (int) $id;
        }

        return response()->json([
            'deleted' => $deleted,
            'failed' => $failed,
        ]);
    }
}
