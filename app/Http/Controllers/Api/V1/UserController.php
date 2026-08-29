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

        if (! in_array($roleKey, ['super_admin', 'admin', 'dept_head', 'department_head', 'sales_head', 'marketing_head'], true)) {
            abort(403, 'You are not authorized to manage users.');
        }
    }

    private function ensureCanManageTargetRole(string $actorRole, string $targetRoleKey): void
    {
        if ($actorRole === 'super_admin') {
            return;
        }

        if ($actorRole === 'admin') {
            if ($targetRoleKey === 'super_admin') {
                abort(403, 'Admins cannot manage super admins.');
            }
            return;
        }

        if ($actorRole === 'sales_head') {
            if (! in_array($targetRoleKey, ['advisor', 'psa'], true)) {
                abort(403, 'Sales Heads can only manage Advisors and PSAs.');
            }
            return;
        }

        if ($actorRole === 'marketing_head') {
            if ($targetRoleKey !== 'telecaller') {
                abort(403, 'Marketing Heads can only manage Telecallers.');
            }
            return;
        }

        if (in_array($actorRole, ['dept_head', 'department_head'], true)) {
            if (in_array($targetRoleKey, ['super_admin', 'admin', 'dept_head', 'department_head', 'sales_head', 'marketing_head'], true)) {
                abort(403, 'Department Heads cannot manage admin or head roles.');
            }
            return;
        }
        
        abort(403, 'You are not authorized to manage this role.');
    }

    private function ensureCanImpersonate(Request $request, User $target): User
    {
        /** @var User|null $actor */
        $actor = $request->user()?->loadMissing('role');
        $actorRoleKey = $actor?->role?->key;

        if (! $actor || ! in_array($actorRoleKey, ['super_admin', 'admin'], true)) {
            abort(403, 'You are not authorized to impersonate users.');
        }

        if ((int) $actor->id === (int) $target->id) {
            abort(422, 'You cannot impersonate your own account.');
        }

        $target->loadMissing('role');
        if ($target->status !== 'active') {
            abort(422, 'Only active users can be impersonated.');
        }

        if ($actorRoleKey === 'admin' && $target->role?->key === 'super_admin') {
            abort(403, 'Admins cannot impersonate super admins.');
        }

        return $actor;
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
            ->with(['role', 'manager:id,first_name,last_name', 'departments']);

        $sortBy = $request->string('sort_by', 'id')->toString();
        $sortDesc = $request->boolean('sort_desc', false);
        $query->orderBy($sortBy, $sortDesc ? 'desc' : 'asc');

        $status = $request->string('status')->toString();
        if ($status === 'deleted' || $status === 'trashed') {
            $query->onlyTrashed();
        } else {
            // Include only active/inactive by default
            $query->when($request->filled('status'), fn ($q) => $q->where('status', $status));
        }

        $query->when($request->filled('q'), fn ($q) => $q->where(function ($sq) use ($request) {
            $needle = trim((string) $request->string('q'));
            $sq->where('first_name', 'like', "%{$needle}%")
                ->orWhere('last_name', 'like', "%{$needle}%")
                ->orWhere('email', 'like', "%{$needle}%")
                ->orWhere('phone', 'like', "%{$needle}%");
        }));

        if ($request->filled('role')) {
            $roles = is_array($request->role) ? $request->role : explode(',', $request->string('role'));
            $query->whereHas('role', fn ($q) => $q->whereIn('key', $roles));
        }

        if ($request->filled('department') && $request->string('department')->toString() !== 'All') {
            $dept = $request->string('department')->toString();
            $query->where(function ($q) use ($dept) {
                $q->where('department', $dept)
                  ->orWhereHas('departments', fn ($dq) => $dq->where('departments.name', $dept));
            });
        }

        $perPage = (int) $request->input('per_page', 50);
        $paginator = $query->paginate(max(1, min(100, $perPage)));

        $checkedInUserIds = AttendanceLog::query()
            ->whereDate('day_date', now()->toDateString())
            ->whereNull('check_out_at')
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        $paginator->getCollection()->transform(function (User $u) use ($checkedInUserIds) {
            $roleKey = $u->role?->key;
            if (in_array($roleKey, ['telecaller', 'psa', 'marketer', 'advisor'], true)) {
                $isOnline = in_array($u->id, $checkedInUserIds, true);
            } else {
                $isOnline = $u->last_login_at && $u->last_login_at->gte(now()->subHour());
            }
            $u->setAttribute('is_online', $isOnline);
            return $u;
        });

        return response()->json($paginator);
    }

    public function show(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);
        $user->load(['role', 'manager:id,first_name,last_name', 'departments']);

        return response()->json($this->appendIsOnline($user));
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

        $roleKey = (string) $data['role_key'];
        $actorRole = $request->user()?->role?->key ?? '';
        $this->ensureCanManageTargetRole($actorRole, $roleKey);

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


        $fresh = $user->fresh()->load(['role', 'manager:id,first_name,last_name', 'departments']);
        return response()->json($this->appendIsOnline($fresh), 201);
    }

    public function update(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);
        $actorRole = $request->user()?->role?->key ?? '';
        $user->loadMissing('role');
        if ($user->role) {
            $this->ensureCanManageTargetRole($actorRole, $user->role->key);
        }

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
            $this->ensureCanManageTargetRole($actorRole, $data['role_key']);
            
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
        $actorRole = $request->user()?->role?->key ?? '';
        $user->loadMissing('role');
        if ($user->role) {
            $this->ensureCanManageTargetRole($actorRole, $user->role->key);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $before = ['status' => $user->status];
        $user->update(['status' => $data['status']]);

        if ($data['status'] === 'inactive') {
            $user->tokens()->delete();
        }


        $fresh = $user->fresh()->load(['role', 'manager:id,first_name,last_name']);
        return response()->json($this->appendIsOnline($fresh));
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);
        $actorRole = $request->user()?->role?->key ?? '';
        $user->loadMissing('role');
        if ($user->role) {
            $this->ensureCanManageTargetRole($actorRole, $user->role->key);
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->update(['password_hash' => Hash::make($data['password'])]);
        $user->tokens()->delete();

        $this->audit($request, 'user.password_reset', $user);

        return response()->json(['message' => 'Password updated']);
    }

    public function impersonate(Request $request, User $user)
    {
        $actor = $this->ensureCanImpersonate($request, $user);
        $expiresAt = now()->addDays(7);
        $actorName = trim(implode(' ', array_filter([$actor->first_name, $actor->last_name]))) ?: $actor->email;

        $token = $user->createToken(
            'impersonation-token',
            ['impersonation', 'impersonated-by:'.$actor->id],
            $expiresAt
        )->plainTextToken;

        $this->audit($request, 'user.impersonate', $user, null, [
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'target_email' => $user->email,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return response()->json([
            'token' => $token,
            'user' => $user->fresh()->load(['role', 'departments']),
            'impersonation' => [
                'actor_id' => $actor->id,
                'actor_name' => $actorName,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    private function getImpersonatorId(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && property_exists($token, 'abilities') && is_iterable($token->abilities)) {
            foreach ($token->abilities as $ability) {
                if (str_starts_with($ability, 'impersonated-by:')) {
                    return (int) substr($ability, strlen('impersonated-by:'));
                }
            }
        }
        return null;
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $impersonatorId = $this->getImpersonatorId($request);
        if ($impersonatorId && $impersonatorId === (int) $user->id) {
            return response()->json(['message' => 'You cannot delete your original account while impersonating.'], 422);
        }

        $this->ensureCanManageUsers($request);
        $actorRole = $request->user()?->role?->key ?? '';
        $user->loadMissing('role');
        if ($user->role) {
            $this->ensureCanManageTargetRole($actorRole, $user->role->key);
        }

        $reason = (string) $request->input('reason', '');

        $user->tokens()->delete();
        $user->delete();


        return response()->json(['message' => 'User deleted']);
    }

    public function forceDelete(Request $request, $id)
    {
        $this->ensureCanManageUsers($request);

        $user = User::withTrashed()->findOrFail($id);
        $actorRole = $request->user()?->role?->key ?? '';
        $user->loadMissing('role');
        if ($user->role) {
            $this->ensureCanManageTargetRole($actorRole, $user->role->key);
        }
        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'You cannot permanently delete your own account.'], 422);
        }

        $impersonatorId = $this->getImpersonatorId($request);
        if ($impersonatorId && $impersonatorId === (int) $user->id) {
            return response()->json(['message' => 'You cannot permanently delete your original account while impersonating.'], 422);
        }

        $user->tokens()->delete();
        $user->forceDelete();

        return response()->json(['message' => 'User permanently deleted']);
    }

    public function stats(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);

        $today = now()->toDateString();

        $user->loadMissing('role');
        $roleKey = $user->role->key ?? 'telecaller';

        $leadsQuery = DB::table('leads')->whereNull('deleted_at');

        if (in_array($roleKey, ['admin', 'super_admin'], true)) {
            // Admins can see all leads
        } elseif (in_array($roleKey, ['dept_head', 'department_head'], true)) {
            $tcIds = DB::table('users')
                ->join('roles', 'users.role_id', '=', 'roles.id')
                ->where('users.reporting_manager_id', $user->id)
                ->where('roles.key', 'telecaller')
                ->pluck('users.id');
            $leadsQuery->whereIn('owner_id', $tcIds);
        } elseif ($roleKey === 'marketer') {
            $leadsQuery->where(function ($q) use ($user) {
                $q->where('generated_by_user_id', $user->id)->orWhere('created_by', $user->id);
            });
        } else {
            $leadsQuery->where('owner_id', $user->id);
        }

        $leadsTotal = (clone $leadsQuery)->count();
        $leadsActive = (clone $leadsQuery)
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
        if ($user) {
            $this->appendIsOnline($user);
        }

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
     * @return \Illuminate\Http\JsonResponse
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
            ->get(['id', 'first_name', 'last_name', 'email', 'role_id', 'department']);

        return response()->json($users);
    }

    public function telecallerCapacities(Request $request)
    {
        $today = now()->toDateString();

        $telecallers = User::query()
            ->whereHas('role', fn ($q) => $q->where('key', 'telecaller'))
            ->where('status', 'active')
            ->withCount([
                'leads as active_leads_count' => function ($q) {
                    $q->whereNull('closed_at');
                },
                'leads as today_assigned_count' => function ($q) use ($today) {
                    $q->whereDate('created_at', $today);
                }
            ])
            ->get()
            ->map(function (User $u) {
                return $this->appendIsOnline($u);
            });

        return response()->json($telecallers);
    }

    public function availablePsas(Request $request)
    {
        $today = now()->toDateString();
        
        $psas = User::query()
            ->whereHas('role', fn ($q) => $q->where('key', 'psa'))
            ->where('status', 'active')
            ->withCount([
                'leads as active_leads_count' => function ($q) {
                    $q->whereNull('closed_at');
                },
                'leads as today_assigned_count' => function ($q) use ($today) {
                    $q->whereDate('created_at', $today);
                }
            ])
            ->get()
            ->map(function (User $u) {
                return $this->appendIsOnline($u);
            });

        return response()->json($psas);
    }

    public function availableAdvisors(Request $request)
    {
        $today = now()->toDateString();

        $advisors = User::query()
            ->whereHas('role', fn ($q) => $q->where('key', 'advisor'))
            ->where('status', 'active')
            ->withCount([
                'leads as active_leads_count' => function ($q) {
                    $q->whereNull('closed_at');
                },
                'leads as today_assigned_count' => function ($q) use ($today) {
                    $q->whereDate('created_at', $today);
                }
            ])
            ->get()
            ->map(function (User $u) {
                return $this->appendIsOnline($u);
            });

        return response()->json($advisors);
    }

    private function appendIsOnline(User $user): User
    {
        $roleKey = $user->role?->key;
        if (in_array($roleKey, ['telecaller', 'psa', 'marketer', 'advisor'], true)) {
            $isOnline = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->whereDate('day_date', now()->toDateString())
                ->whereNull('check_out_at')
                ->exists();
        } else {
            $isOnline = $user->last_login_at && $user->last_login_at->gte(now()->subHour());
        }
        $user->setAttribute('is_online', $isOnline);
        return $user;
    }

    public function restore(Request $request, $id)
    {
        $this->ensureCanManageUsers($request);

        $user = User::withTrashed()->findOrFail($id);
        $actorRole = $request->user()?->role?->key ?? '';
        $user->loadMissing('role');
        if ($user->role) {
            $this->ensureCanManageTargetRole($actorRole, $user->role->key);
        }
        if (!$user->trashed()) {
            return response()->json(['message' => 'User is not deleted.'], 422);
        }

        $user->restore();

        return response()->json([
            'message' => 'User restored successfully.',
            'user' => $user->fresh()->load(['role', 'departments'])
        ]);
    }

    public function bulkActivate(Request $request)
    {
        $this->ensureCanManageUsers($request);
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $actorRole = $request->user()?->role?->key ?? '';

        $users = User::whereIn('id', $data['ids'])->get();
        foreach ($users as $user) {
            $user->loadMissing('role');
            if ($user->role) {
                $this->ensureCanManageTargetRole($actorRole, $user->role->key);
            }
            $user->update(['status' => 'active']);
        }

        return response()->json(['message' => 'Users activated.']);
    }

    public function bulkDeactivate(Request $request)
    {
        $this->ensureCanManageUsers($request);
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $actorRole = $request->user()?->role?->key ?? '';

        $users = User::whereIn('id', $data['ids'])->get();
        foreach ($users as $user) {
            $user->loadMissing('role');
            if ($user->role) {
                $this->ensureCanManageTargetRole($actorRole, $user->role->key);
            }
            $user->update(['status' => 'inactive']);
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'Users deactivated.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->ensureCanManageUsers($request);
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $actorRole = $request->user()?->role?->key ?? '';

        $users = User::whereIn('id', $data['ids'])->get();
        $impersonatorId = $this->getImpersonatorId($request);

        foreach ($users as $user) {
            $user->loadMissing('role');
            if ($user->role) {
                $this->ensureCanManageTargetRole($actorRole, $user->role->key);
            }
            if ((int) $user->id !== (int) $request->user()?->id && ((int) $user->id !== $impersonatorId)) {
                $user->tokens()->delete();
                $user->delete();
            }
        }

        return response()->json(['message' => 'Users deleted.']);
    }

    public function bulkRestore(Request $request)
    {
        $this->ensureCanManageUsers($request);
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $actorRole = $request->user()?->role?->key ?? '';

        $users = User::withTrashed()->whereIn('id', $data['ids'])->get();
        foreach ($users as $user) {
            $user->loadMissing('role');
            if ($user->role) {
                $this->ensureCanManageTargetRole($actorRole, $user->role->key);
            }
            if ($user->trashed()) {
                $user->restore();
            }
        }

        return response()->json(['message' => 'Users restored.']);
    }

    public function exportCsv(Request $request)
    {
        $this->ensureCanManageUsers($request);

        $query = User::query()->with(['role', 'departments'])->orderBy('id');

        $status = $request->string('status')->toString();
        if ($status === 'deleted' || $status === 'trashed') {
            $query->onlyTrashed();
        } else {
            $query->when($request->filled('status'), fn ($q) => $q->where('status', $status));
        }

        $query->when($request->filled('q'), fn ($q) => $q->where(function ($sq) use ($request) {
            $needle = trim((string) $request->string('q'));
            $sq->where('first_name', 'like', "%{$needle}%")
                ->orWhere('last_name', 'like', "%{$needle}%")
                ->orWhere('email', 'like', "%{$needle}%")
                ->orWhere('phone', 'like', "%{$needle}%");
        }));

        if ($request->filled('role')) {
            $roles = is_array($request->role) ? $request->role : explode(',', $request->string('role'));
            $query->whereHas('role', fn ($q) => $q->whereIn('key', $roles));
        }

        if ($request->filled('department') && $request->string('department')->toString() !== 'All') {
            $dept = $request->string('department')->toString();
            $query->where(function ($q) use ($dept) {
                $q->where('department', $dept)
                  ->orWhereHas('departments', fn ($dq) => $dq->where('departments.name', $dept));
            });
        }

        if ($request->filled('ids')) {
            $ids = is_array($request->ids) ? $request->ids : explode(',', $request->string('ids'));
            $query->whereIn('id', $ids);
        }

        $callback = function () use ($query) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'Name', 'Email', 'Phone', 'Role', 'Department', 'Sub-brand', 'Status', 'Date Created', 'Deleted At']);

            $query->chunk(100, function ($users) use ($file) {
                foreach ($users as $user) {
                    $name = trim($user->first_name . ' ' . $user->last_name);
                    $deptNames = $user->departments->pluck('name')->join('; ') ?: $user->department;
                    fputcsv($file, [
                        $user->id,
                        $name,
                        $user->email,
                        $user->phone,
                        $user->role?->name ?? 'Unknown',
                        $deptNames,
                        $user->sub_brand,
                        $user->trashed() ? 'deleted' : $user->status,
                        $user->created_at?->format('Y-m-d H:i:s'),
                        $user->deleted_at?->format('Y-m-d H:i:s')
                    ]);
                }
            });
            fclose($file);
        };

        $this->audit($request, 'user.export', $request->user());

        return response()->streamDownload($callback, 'users_export_' . date('Ymd_His') . '.csv', [
            'Content-Type' => 'text/csv'
        ]);
    }
}
