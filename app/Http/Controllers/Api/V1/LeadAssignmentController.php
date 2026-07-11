<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use App\Models\LeadAssignment;
use Illuminate\Http\Request;

class LeadAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $query = LeadAssignment::query()->with([
            'lead:id,student_name,phone,email,source_code,campaign,source_group',
            'previousOwner:id,first_name,last_name,email',
            'newOwner:id,first_name,last_name,email',
            'assignedBy:id,first_name,last_name,email',
            'department:id,name,code',
            'campaign:id,name',
        ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('lead', function ($q) use ($search) {
                $q->where('student_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('assignment_type')) {
            $query->where('assignment_type', $request->input('assignment_type'));
        }

        if ($request->filled('assigned_by')) {
            $query->where('assigned_by', $request->input('assigned_by'));
        }

        if ($request->filled('current_owner')) {
            $query->where('new_owner_id', $request->input('current_owner'));
        }

        if ($request->filled('previous_owner_id')) {
            $query->where('previous_owner_id', $request->input('previous_owner_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->input('campaign_id'));
        }

        if ($request->filled('lead_source')) {
            $source = $request->input('lead_source');
            $query->whereHas('lead', function ($q) use ($source) {
                $q->where('source_code', $source)
                  ->orWhere('source_group', $source);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $query->orderBy('created_at', 'desc');

        $limit = min(100, $request->integer('limit', 20));
        return response()->json($query->paginate($limit));
    }

    public function show(Lead $lead)
    {
        $assignments = LeadAssignment::query()
            ->with([
                'previousOwner:id,first_name,last_name,email',
                'newOwner:id,first_name,last_name,email',
                'assignedBy:id,first_name,last_name,email',
                'department:id,name,code',
                'campaign:id,name',
            ])
            ->where('lead_id', $lead->id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($assignments);
    }

    public function assign(Request $request, Lead $lead)
    {
        $request->validate([
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $ownerId = $request->input('owner_id');

        if ($ownerId) {
            $telecaller = User::findOrFail($ownerId);
            if ($request->user() && $request->user()->role === 'marketer') {
                $telecaller->loadMissing('role');
                if ($telecaller->role?->key !== 'telecaller') {
                    return response()->json(['message' => 'MARKETER_CAN_ONLY_ASSIGN_TELECALLER'], 403);
                }
            }

            $lead->assignment_type = 'Initial Assignment';
            $lead->assignment_reason = $request->input('reason') ?? 'Initial Telecaller Assignment';

            $lead->update([
                'owner_id' => $telecaller->id,
                'assignment_status' => 'assigned',
                'routing_failed' => false,
            ]);
        } else {
            $lead->assignment_type = 'Remove Telecaller Assignment';
            $lead->assignment_reason = $request->input('reason') ?? 'Removed Telecaller Assignment';

            $lead->update([
                'owner_id' => null,
                'assignment_status' => 'waiting',
            ]);
        }

        return response()->json([
            'message' => 'LEAD_ASSIGN_SUCCESSFUL',
            'lead' => $lead->fresh(['owner', 'stage']),
        ]);
    }

    public function reassign(Request $request, Lead $lead)
    {
        $request->validate([
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $ownerId = $request->input('owner_id');

        if ($ownerId) {
            $telecaller = User::findOrFail($ownerId);
            if ($request->user() && $request->user()->role === 'marketer') {
                $telecaller->loadMissing('role');
                if ($telecaller->role?->key !== 'telecaller') {
                    return response()->json(['message' => 'MARKETER_CAN_ONLY_ASSIGN_TELECALLER'], 403);
                }
            }

            $lead->assignment_type = 'Manual Reassignment';
            $lead->assignment_reason = $request->input('reason') ?? 'Manual Telecaller Reassignment';

            $lead->update([
                'owner_id' => $telecaller->id,
                'assignment_status' => 'assigned',
                'routing_failed' => false,
            ]);
        } else {
            $lead->assignment_type = 'Remove Telecaller Assignment';
            $lead->assignment_reason = $request->input('reason') ?? 'Removed Telecaller Assignment';

            $lead->update([
                'owner_id' => null,
                'assignment_status' => 'waiting',
            ]);
        }

        return response()->json([
            'message' => 'LEAD_REASSIGN_SUCCESSFUL',
            'lead' => $lead->fresh(['owner', 'stage']),
        ]);
    }
}
