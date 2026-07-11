<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadClosedReason;
use App\Models\LeadStage;
use App\Models\LeadStageAutomation;
use App\Models\LeadStagePermission;
use App\Models\LeadStageRequiredField;
use App\Models\LeadStageRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadStageController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $userRole = $user?->role?->key ?? 'guest';

        $stages = LeadStage::query()
            ->active()
            ->orderBy('order')
            ->with(['rulesFrom', 'permissions', 'automations', 'requiredFields'])
            ->get()
            ->map(function ($stage) use ($userRole) {
                $permission = $stage->permissions->firstWhere('role', $userRole);

                return [
                    'id' => $stage->id,
                    'key' => $stage->key,
                    'label' => $stage->label,
                    'group' => $stage->group,
                    'type' => $stage->type,
                    'order' => $stage->order,
                    'color' => $stage->color,
                    'is_terminal' => $stage->is_terminal,
                    'is_active' => $stage->is_active,
                    'description' => $stage->description,
                    'owner_role' => $stage->owner_role,
                    'sla_hours' => $stage->sla_hours,
                    'legacy_status' => $stage->legacy_status,
                    'allowed_next' => $stage->rulesFrom->where('is_active', true)->pluck('to_stage_id')->values(),
                    'permissions' => $stage->permissions->keyBy('role'),
                    'user_permission' => $permission ? [
                        'can_view' => (bool) $permission->can_view,
                        'can_move' => (bool) $permission->can_move,
                        'can_override' => (bool) $permission->can_override,
                        'can_close' => (bool) $permission->can_close,
                        'can_reopen' => (bool) $permission->can_reopen,
                        'can_delete' => (bool) $permission->can_delete,
                    ] : [
                        'can_view' => true,
                        'can_move' => false,
                        'can_override' => false,
                        'can_close' => false,
                        'can_reopen' => false,
                        'can_delete' => false,
                    ],
                    'automations' => $stage->automations,
                    'required_fields' => $stage->requiredFields,
                ];
            });

        $closedReasons = LeadClosedReason::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'stages' => $stages,
            'closed_reasons' => $closedReasons,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:40|unique:lead_stages,key',
            'label' => 'required|string|max:80',
            'group' => 'required|in:active,inactive',
            'type' => 'required|in:open,won,lost',
            'color' => 'nullable|string|max:16',
            'owner_role' => 'nullable|string|max:40',
            'sla_hours' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'legacy_status' => 'nullable|string|max:40',
        ]);

        $maxOrder = LeadStage::max('order') ?? 0;
        $validated['order'] = $maxOrder + 1;
        $validated['is_active'] = true;

        $stage = LeadStage::create($validated);

        // Seed default permissions for all roles
        $roles = ['super_admin', 'admin', 'sales_head', 'department_head', 'telecaller', 'psa', 'advisor', 'marketer'];
        foreach ($roles as $role) {
            $isSuperOrHead = in_array($role, ['super_admin', 'admin', 'sales_head', 'department_head'], true);
            $isOwnerRole = ($stage->owner_role === $role);

            LeadStagePermission::create([
                'lead_stage_id' => $stage->id,
                'role' => $role,
                'can_view' => true,
                'can_move' => $isSuperOrHead || $isOwnerRole,
                'can_override' => $isSuperOrHead,
                'can_close' => $isSuperOrHead || $isOwnerRole,
                'can_reopen' => $isSuperOrHead,
                'can_delete' => in_array($role, ['super_admin', 'admin'], true),
            ]);
        }

        return response()->json($stage->load(['permissions', 'rulesFrom', 'automations', 'requiredFields']), 201);
    }

    public function update(Request $request, LeadStage $leadStage)
    {
        $validated = $request->validate([
            'label' => 'sometimes|string|max:80',
            'color' => 'sometimes|nullable|string|max:16',
            'owner_role' => 'sometimes|nullable|string|max:40',
            'sla_hours' => 'sometimes|nullable|integer|min:0',
            'type' => 'sometimes|in:open,won,lost',
            'description' => 'sometimes|nullable|string',
            'legacy_status' => 'sometimes|nullable|string|max:40',
            'is_active' => 'sometimes|boolean',
            'allowed_next_ids' => 'sometimes|array',
            'allowed_next_ids.*' => 'integer|exists:lead_stages,id',
            'permissions' => 'sometimes|array',
            'required_fields' => 'sometimes|array',
            'automations' => 'sometimes|array',
        ]);

        DB::transaction(function () use ($leadStage, $validated) {
            $leadStage->update($validated);

            if (isset($validated['allowed_next_ids'])) {
                LeadStageRule::where('from_stage_id', $leadStage->id)->delete();
                foreach ($validated['allowed_next_ids'] as $toId) {
                    LeadStageRule::create([
                        'from_stage_id' => $leadStage->id,
                        'to_stage_id' => $toId,
                        'is_active' => true,
                    ]);
                }
            }

            if (isset($validated['permissions'])) {
                foreach ($validated['permissions'] as $role => $perm) {
                    LeadStagePermission::updateOrCreate(
                        ['lead_stage_id' => $leadStage->id, 'role' => $role],
                        [
                            'can_view' => $perm['can_view'] ?? true,
                            'can_move' => $perm['can_move'] ?? false,
                            'can_override' => $perm['can_override'] ?? false,
                            'can_close' => $perm['can_close'] ?? false,
                            'can_reopen' => $perm['can_reopen'] ?? false,
                            'can_delete' => $perm['can_delete'] ?? false,
                        ]
                    );
                }
            }

            if (isset($validated['required_fields'])) {
                LeadStageRequiredField::where('lead_stage_id', $leadStage->id)->delete();
                foreach ($validated['required_fields'] as $req) {
                    if (! empty($req['field_name'])) {
                        LeadStageRequiredField::create([
                            'lead_stage_id' => $leadStage->id,
                            'field_name' => $req['field_name'],
                            'field_label' => $req['field_label'] ?? ucfirst(str_replace('_', ' ', $req['field_name'])),
                            'is_required' => true,
                        ]);
                    }
                }
            }

            if (isset($validated['automations'])) {
                LeadStageAutomation::where('lead_stage_id', $leadStage->id)->delete();
                foreach ($validated['automations'] as $idx => $auto) {
                    if (! empty($auto['action'])) {
                        LeadStageAutomation::create([
                            'lead_stage_id' => $leadStage->id,
                            'action' => $auto['action'],
                            'target_role' => $auto['target_role'] ?? null,
                            'task_template' => $auto['task_template'] ?? null,
                            'is_active' => $auto['is_active'] ?? true,
                            'sort_order' => $idx + 1,
                        ]);
                    }
                }
            }
        });

        return response()->json($leadStage->fresh(['permissions', 'rulesFrom', 'automations', 'requiredFields']));
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer|exists:lead_stages,id',
        ]);

        foreach ($validated['ordered_ids'] as $idx => $id) {
            LeadStage::where('id', $id)->update(['order' => $idx + 1]);
        }

        return response()->json(['message' => 'Stages reordered successfully']);
    }

    public function destroy(LeadStage $leadStage)
    {
        $leadStage->update(['is_active' => false]);
        return response()->json(['message' => 'Stage deactivated successfully']);
    }
}
