<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    private function ensureSettingsAdmin(Request $request): void
    {
        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        if (! in_array($roleKey, ['super_admin', 'admin', 'dept_head'], true)) {
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

        $query = Department::query()->withCount('users')->with('head');

        // Handling trashed items
        if ($request->input('status') === 'trashed') {
            $query->onlyTrashed();
        } elseif ($request->input('status') === 'active') {
            $query->where('status', 'active');
        } elseif ($request->input('status') === 'inactive') {
            $query->where('status', 'inactive');
        }

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhereHas('head', function ($q2) use ($search) {
                      $q2->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        $sortField = $request->input('sort_by', 'name');
        $sortDesc = filter_var($request->input('sort_desc', false), FILTER_VALIDATE_BOOLEAN);
        $direction = $sortDesc ? 'desc' : 'asc';
        
        $allowedSorts = ['name', 'code', 'category', 'status', 'created_at', 'users_count'];
        if (in_array($sortField, $allowedSorts, true)) {
            $query->orderBy($sortField, $direction);
        } else {
            $query->orderBy('name', 'asc');
        }

        $perPage = (int) $request->input('per_page', 15);
        if ($perPage === -1) {
            $perPage = $query->count() ?: 15;
        }

        $departments = $query->paginate($perPage);

        // Stats
        $baseQuery = Department::query();
        $total = clone $baseQuery;
        $activeCount = (clone $baseQuery)->where('status', 'active')->count();
        $largest = (clone $baseQuery)->withCount('users')->orderByDesc('users_count')->first();

        return response()->json([
            'data' => $departments->items(),
            'meta' => [
                'current_page' => $departments->currentPage(),
                'last_page' => $departments->lastPage(),
                'per_page' => $departments->perPage(),
                'total' => $departments->total(),
            ],
            'stats' => [
                'total' => $total->count(),
                'active_count' => $activeCount,
                'inactive_count' => $total->count() - $activeCount,
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
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:50'],
            'head_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $code = isset($data['code']) ? strtoupper($data['code']) : $this->generateUniqueCode($data['name']);

        $department = Department::query()->create([
            'code' => $code,
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'status' => $data['status'] ?? 'active',
            'description' => $data['description'] ?? null,
            'color' => $data['color'] ?? null,
            'icon' => $data['icon'] ?? null,
            'head_id' => $data['head_id'] ?? null,
        ]);

        return response()->json($department->load('head')->loadCount('users'), 201);
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

    public function show(Request $request, int $id)
    {
        $this->ensureCanListDepartments($request);

        $department = Department::withTrashed()->with('head')->withCount('users')->findOrFail($id);

        $userIds = $department->users()->pluck('users.id');
        
        // Compute statistics for the department
        $openLeads = \App\Models\Lead::whereIn('owner_id', $userIds)
            ->whereNotIn('status', ['won', 'lost', 'closed'])
            ->count();
            
        $closedLeads = \App\Models\Lead::whereIn('owner_id', $userIds)
            ->whereIn('status', ['won', 'closed'])
            ->count();

        // Calculate attendance today
        $totalUsers = $userIds->count();
        $attendedToday = 0;
        if ($totalUsers > 0) {
            $attendedToday = \App\Models\Attendance::whereIn('user_id', $userIds)
                ->whereDate('date', now()->toDateString())
                ->whereNotNull('check_in')
                ->count();
        }

        $conversion = ($closedLeads + $openLeads > 0) 
            ? round(($closedLeads / ($closedLeads + $openLeads)) * 100, 1) 
            : 0;

        return response()->json([
            'department' => $department,
            'statistics' => [
                'total_employees' => $totalUsers,
                'attendance_percent' => $totalUsers > 0 ? round(($attendedToday / $totalUsers) * 100, 1) : 0,
                'open_leads' => $openLeads,
                'closed_leads' => $closedLeads,
                'conversion_rate' => $conversion,
            ]
        ]);
    }

    public function update(Request $request, Department $department)
    {
        $this->ensureSettingsAdmin($request);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($department->id)],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'code' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('departments', 'code')->ignore($department->id)],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:50'],
            'head_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $department->update($data);

        return response()->json($department->fresh('head')->loadCount('users'));
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

    public function restore(Request $request, int $id)
    {
        $this->ensureSettingsAdmin($request);
        $department = Department::onlyTrashed()->findOrFail($id);
        $department->restore();
        return response()->json(['message' => 'Department restored successfully.']);
    }

    public function members(Request $request, int $id)
    {
        $this->ensureCanListDepartments($request);
        $department = Department::withTrashed()->findOrFail($id);

        $query = $department->users()->with('role');

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        $perPage = (int) $request->input('per_page', 15);
        $users = $query->paginate($perPage);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ]
        ]);
    }

    public function bulkActivate(Request $request)
    {
        $this->ensureSettingsAdmin($request);
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer|exists:departments,id']);
        Department::whereIn('id', $data['ids'])->update(['status' => 'active']);
        return response()->json(['message' => count($data['ids']) . ' departments activated.']);
    }

    public function bulkDeactivate(Request $request)
    {
        $this->ensureSettingsAdmin($request);
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer|exists:departments,id']);
        Department::whereIn('id', $data['ids'])->update(['status' => 'inactive']);
        return response()->json(['message' => count($data['ids']) . ' departments deactivated.']);
    }

    public function bulkRestore(Request $request)
    {
        $this->ensureSettingsAdmin($request);
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $restored = 0;
        foreach ($data['ids'] as $id) {
            $dept = Department::onlyTrashed()->find($id);
            if ($dept) {
                $dept->restore();
                $restored++;
            }
        }
        return response()->json(['message' => "$restored departments restored."]);
    }

    public function bulkDestroy(Request $request)
    {
        $this->ensureSettingsAdmin($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'], // Can be trashed or active
        ]);

        $deleted = [];
        $failed = [];

        foreach ($data['ids'] as $id) {
            $department = Department::withTrashed()->find($id);
            if (! $department) {
                continue;
            }
            if ($department->users()->exists()) {
                $failed[] = ['id' => (int) $id, 'reason' => 'Department has users assigned.'];
                continue;
            }
            if ($department->trashed()) {
                $department->forceDelete();
            } else {
                $department->delete();
            }
            $deleted[] = (int) $id;
        }

        return response()->json([
            'deleted' => $deleted,
            'failed' => $failed,
            'message' => count($deleted) . ' departments deleted.'
        ]);
    }
}
