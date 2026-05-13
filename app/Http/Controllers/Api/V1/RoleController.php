<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::query()
            ->orderByDesc('permission_level')
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'permission_level']);

        return response()->json(['data' => $roles]);
    }
}
