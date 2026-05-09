<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function ensureCanManageUsers(Request $request): void
    {
        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        if (! in_array($roleKey, ['super_admin', 'admin', 'dept_head'], true)) {
            abort(403, 'You are not authorized to manage users.');
        }
    }

    public function index(Request $request)
    {
        $this->ensureCanManageUsers($request);

        $query = User::query()
            ->with(['role', 'manager:id,first_name,last_name'])
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

    public function store(Request $request)
    {
        $this->ensureCanManageUsers($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role_key' => ['required', 'string', 'exists:roles,key'],
            'department' => ['nullable', Rule::in(['PM', 'IM', 'SALES', 'OPS'])],
            'reporting_manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $roleId = Role::query()->where('key', $data['role_key'])->value('id');
        if (! $roleId) {
            return response()->json(['message' => 'Invalid role'], 422);
        }

        $user = User::query()->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'role_id' => $roleId,
            'department' => $data['department'] ?? null,
            'reporting_manager_id' => $data['reporting_manager_id'] ?? null,
            'status' => 'active',
            'password_hash' => Hash::make($data['password']),
        ]);

        return response()->json($user->load(['role', 'manager:id,first_name,last_name']), 201);
    }

    public function update(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role_key' => ['nullable', 'string', 'exists:roles,key'],
            'department' => ['nullable', Rule::in(['PM', 'IM', 'SALES', 'OPS'])],
            'reporting_manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (array_key_exists('reporting_manager_id', $data) && (int) $data['reporting_manager_id'] === (int) $user->id) {
            return response()->json(['message' => 'User cannot report to self'], 422);
        }

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

        $user->update($data);

        return response()->json($user->fresh()->load(['role', 'manager:id,first_name,last_name']));
    }

    public function updateStatus(Request $request, User $user)
    {
        $this->ensureCanManageUsers($request);

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $user->update(['status' => $data['status']]);
        if ($data['status'] === 'inactive') {
            $user->tokens()->delete();
        }

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

        return response()->json(['message' => 'Password updated']);
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $this->ensureCanManageUsers($request);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }
}
