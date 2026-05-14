<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\LeadActivity;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Avoid SQL errors when the DB predates profile migrations (e.g. older SQL dumps).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function onlyExistingUserColumns(array $attributes): array
    {
        $columns = array_flip(Schema::getColumnListing((new User)->getTable()));

        return array_intersect_key($attributes, $columns);
    }

    private function ensureCanManageUsers(Request $request): void
    {
        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        if (! in_array($roleKey, ['super_admin', 'admin', 'dept_head'], true)) {
            abort(403, 'You are not authorized to manage users.');
        }
    }

    private function audit(Request $request, string $action, User $target, ?array $old = null, ?array $new = null): void
    {
        AuditLog::query()->create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $target->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->header('User-Agent'), 0, 255) ?: null,
        ]);
    }

    /**
     * @param  list<int>  $departmentIds
     */
    private function assertDeptHeadSingleDepartment(string $roleKey, array $departmentIds): void
    {
        if ($roleKey !== 'dept_head') {
            return;
        }

        if (count($departmentIds) !== 1) {
            throw ValidationException::withMessages([
                'department_ids' => ['Department heads must be assigned to exactly one department.'],
            ]);
        }
    }

    /**
     * @param  list<int>|null  $departmentIds
     */
    private function syncUserDepartments(User $user, ?array $departmentIds, ?int $primaryDepartmentId, string $roleKey): void
    {
        $ids = array_values(array_unique(array_map('intval', $departmentIds ?? [])));

        $this->assertDeptHeadSingleDepartment($roleKey, $ids);

        if ($ids === []) {
            $user->departments()->detach();
            $user->update(['department' => null]);

            return;
        }

        $primaryId = $primaryDepartmentId !== null ? (int) $primaryDepartmentId : (int) $ids[0];
        if (! in_array($primaryId, $ids, true)) {
            throw ValidationException::withMessages([
                'primary_department_id' => ['Primary department must be one of the selected departments.'],
            ]);
        }

        $sync = [];
        foreach ($ids as $id) {
            $sync[$id] = ['is_primary' => $id === $primaryId];
        }
        $user->departments()->sync($sync);

        $code = Department::query()->whereKey($primaryId)->value('code');
        $user->update(['department' => $code]);
    }

    public function index(Request $request)
    {
        $this->ensureCanManageUsers($request);

        $query = User::query()
            ->with(['role', 'manager:id,first_name,last_name', 'departments'])
            ->orderBy('id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where(function ($sq) use ($request) {
                    $needle = trim((string) $request->string('q'));
                    $sq->where('first_name', 'like', "%{$needle}%")
                        ->orWhere('last_name', 'like', "%{$needle}%")
                        ->orWhere('email', 'like', "%{$needle}%")
                        ->orWhere('phone', 'like', "%{$needle}%");
                })
            );

        if ($request->filled('role')) {
            $roleKey = (string) $request->string('role');
            $query->whereHas('role', fn ($q) => $q->where('key', $roleKey));
        }

        $users = $query->get();

        return response()->json($users);
    }

    public function show(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);

        return response()->json($user->load(['role', 'manager:id,first_name,last_name', 'departments']));
    }

    public function store(Request $request)
    {
        $this->ensureCanManageUsers($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'role_key' => ['required', 'string', 'exists:roles,key'],
            'department' => ['nullable', 'string', 'max:32', Rule::exists('departments', 'code')],
            'department_ids' => ['sometimes', 'array', 'max:20'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'primary_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'sub_brand' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'reporting_manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $roleId = Role::query()->where('key', $data['role_key'])->value('id');
        if (! $roleId) {
            return response()->json(['message' => 'Invalid role'], 422);
        }

        $departmentIdsPayload = $data['department_ids'] ?? null;
        $primaryDepartmentId = $data['primary_department_id'] ?? null;
        $legacyDepartmentCode = $data['department'] ?? null;
        unset($data['department_ids'], $data['primary_department_id'], $data['department']);

        $user = User::query()->create($this->onlyExistingUserColumns([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'role_id' => $roleId,
            'department' => null,
            'sub_brand' => $data['sub_brand'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'reporting_manager_id' => $data['reporting_manager_id'] ?? null,
            'status' => 'active',
            'password_hash' => Hash::make($data['password']),
        ]));

        $roleKey = (string) $data['role_key'];
        if ($request->has('department_ids')) {
            $this->syncUserDepartments($user, $departmentIdsPayload !== null ? array_values($departmentIdsPayload) : [], $primaryDepartmentId, $roleKey);
        } elseif ($legacyDepartmentCode) {
            $dept = Department::query()->where('code', $legacyDepartmentCode)->first();
            if ($dept) {
                $this->syncUserDepartments($user, [(int) $dept->id], (int) $dept->id, $roleKey);
            }
        }

        $this->audit($request, 'user.create', $user, null, $user->only(['email', 'role_id', 'department']));

        return response()->json($user->fresh()->load(['role', 'manager:id,first_name,last_name', 'departments']), 201);
    }

    public function update(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'role_key' => ['nullable', 'string', 'exists:roles,key'],
            'department' => ['nullable', 'string', 'max:32', Rule::exists('departments', 'code')],
            'department_ids' => ['sometimes', 'array', 'max:20'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'primary_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'sub_brand' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'reporting_manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (array_key_exists('reporting_manager_id', $data) && (int) $data['reporting_manager_id'] === (int) $user->id) {
            return response()->json(['message' => 'User cannot report to self'], 422);
        }

        $departmentIdsPayload = array_key_exists('department_ids', $data) ? $data['department_ids'] : null;
        $primaryDepartmentId = $data['primary_department_id'] ?? null;
        $legacyDepartmentCode = array_key_exists('department', $data) ? $data['department'] : false;
        unset($data['department_ids'], $data['primary_department_id'], $data['department']);

        if (! empty($data['role_key'])) {
            $roleId = Role::query()->where('key', $data['role_key'])->value('id');
            if (! $roleId) {
                return response()->json(['message' => 'Invalid role'], 422);
            }
            $data['role_id'] = $roleId;
        }

        unset($data['role_key']);

        if (array_key_exists('email', $data)) {
            $data['email'] = strtolower((string) $data['email']);
        }

        $payload = $this->onlyExistingUserColumns($data);
        $before = $user->only(array_keys($payload));
        $user->update($payload);
        $this->audit($request, 'user.update', $user, $before, $user->only(array_keys($payload)));

        $user->refresh()->load('role');
        $roleKey = (string) $user->role?->key;

        if ($request->has('department_ids')) {
            $this->syncUserDepartments(
                $user,
                $departmentIdsPayload !== null ? array_values($departmentIdsPayload) : [],
                $primaryDepartmentId,
                $roleKey
            );
        } elseif ($legacyDepartmentCode !== false) {
            if ($legacyDepartmentCode === null || $legacyDepartmentCode === '') {
                $this->syncUserDepartments($user, [], null, $roleKey);
            } else {
                $dept = Department::query()->where('code', (string) $legacyDepartmentCode)->first();
                if ($dept) {
                    $this->syncUserDepartments($user, [(int) $dept->id], (int) $dept->id, $roleKey);
                }
            }
        }

        return response()->json($user->fresh()->load(['role', 'manager:id,first_name,last_name', 'departments']));
    }

    public function updateStatus(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $before = ['status' => $user->status];
        $user->update(['status' => $data['status']]);

        if ($data['status'] === 'inactive') {
            $user->tokens()->delete();
        }

        $this->audit($request, 'user.status_change', $user, $before, [
            'status' => $data['status'],
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json($user->fresh()->load(['role', 'manager:id,first_name,last_name']));
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->update(['password_hash' => Hash::make($data['password'])]);
        $user->tokens()->delete();

        $this->audit($request, 'user.password_reset', $user);

        return response()->json(['message' => 'Password updated']);
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $this->ensureCanManageUsers($request);

        $reason = (string) $request->input('reason', '');

        $user->tokens()->delete();
        $user->delete();

        $this->audit($request, 'user.delete', $user, null, ['reason' => $reason ?: null]);

        return response()->json(['message' => 'User deleted']);
    }

    public function stats(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);

        $today = now()->toDateString();

        $leadsTotal = DB::table('leads')->where('owner_id', $user->id)->whereNull('deleted_at')->count();
        $leadsActive = DB::table('leads')
            ->where('owner_id', $user->id)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('status')->orWhereNotIn('status', ['Enrolled', 'Disqualified']);
            })
            ->count();
        $waLeadsToday = Schema::hasColumn('leads', 'captured_by_user_id')
            ? DB::table('leads')
                ->where('captured_by_user_id', $user->id)
                ->where('source_code', 'whatsapp')
                ->whereDate('created_at', $today)
                ->whereNull('deleted_at')
                ->count()
            : 0;
        $activitiesLast7 = LeadActivity::query()
            ->where('user_id', $user->id)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->count();
        $lastAttendance = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('check_in_at')
            ->value('check_in_at');

        $waSession = Schema::hasTable('whatsapp_sessions')
            ? WhatsAppSession::query()
                ->where('user_id', $user->id)
                ->where('session_name', 'default')
                ->first(['status', 'phone_number', 'last_sync'])
            : null;

        return response()->json([
            'leads_owned_total' => $leadsTotal,
            'leads_owned_active' => $leadsActive,
            'whatsapp_leads_today' => $waLeadsToday,
            'activities_last_7d' => $activitiesLast7,
            'last_attendance_at' => $lastAttendance,
            'whatsapp_session' => $waSession,
        ]);
    }

    public function activities(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);

        $limit = (int) $request->input('limit', 25);
        $limit = max(1, min(100, $limit));

        $rows = LeadActivity::query()
            ->where('user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function me(Request $request)
    {
        $user = $request->user()?->load(['role', 'manager:id,first_name,last_name', 'departments']);

        return response()->json($user);
    }

    public function updateMe(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
        ]);

        $user->update($this->onlyExistingUserColumns($data));

        return response()->json($user->fresh()->load(['role', 'manager:id,first_name,last_name', 'departments']));
    }

    /**
     * Active users for the lead capture "Lead generated by" field.
     *
     * @return \Illuminate\Http\JsonResponse<int, \App\Models\User>
     */
    public function forLeadForm(Request $request)
    {
        $keys = $request->input('role_keys');
        if (is_string($keys)) {
            $keys = array_values(array_filter(array_map('trim', explode(',', $keys))));
        }
        if (! is_array($keys) || $keys === []) {
            $keys = ['super_admin', 'admin', 'marketer', 'dept_head', 'telecaller', 'psa', 'advisor', 'sales_head'];
        }

        $users = User::query()
            ->with(['role:id,key'])
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('key', $keys))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'role_id']);

        return response()->json($users);
    }
}
