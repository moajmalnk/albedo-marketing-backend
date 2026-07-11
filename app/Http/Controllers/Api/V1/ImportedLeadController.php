<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ImportedLeadController extends Controller
{
    /**
     * Get list of imported leads (stage = new_lead, active only)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        $stageId = LeadStage::where('key', 'new_lead')->value('id');

        $query = Lead::query()
            ->where('stage_id', $stageId)
            ->with(['stage', 'campaign', 'owner']);

        // Marketer role gate
        if ($roleKey === 'marketer') {
            $query->where(function($q) use ($user) {
                $q->where('generated_by_user_id', $user->id)
                  ->orWhere('created_by', $user->id);
            });
        } elseif ($roleKey === 'dept_head') {
            $dept = $user->department;
            if ($dept === 'PM') {
                $query->where('source_group', 'performance');
            } elseif ($dept === 'IM') {
                $query->where('source_group', 'influence');
            }
        }

        if ($request->filled('q')) {
            $needle = trim((string) $request->string('q'));
            if ($needle !== '') {
                $query->where(function ($sq) use ($needle) {
                    $sq->where('student_name', 'like', '%'.$needle.'%')
                       ->orWhere('phone', 'like', '%'.$needle.'%')
                       ->orWhere('email', 'like', '%'.$needle.'%');
                });
            }
        }

        if ($request->filled('source_code')) {
            $query->where('source_code', $request->input('source_code'));
        }

        if ($request->filled('campaign')) {
            $query->where('campaign', $request->input('campaign'));
        }

        $query->orderByDesc('created_at');

        $perPage = min(100, $request->integer('per_page', 50));
        return response()->json($query->paginate($perPage));
    }

    /**
     * Create a manual imported lead
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        if (!in_array($roleKey, ['super_admin', 'admin', 'dept_head', 'marketer'])) {
            return response()->json(['message' => 'UNAUTHORIZED_ROLE'], 403);
        }

        $data = $request->validate([
            'student_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:20'],
            'alternate_phone' => ['nullable', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:160'],
            'parent_name' => ['nullable', 'string', 'max:160'],
            'parent_relation' => ['nullable', 'string', Rule::in(['father', 'mother', 'guardian'])],
            'class' => ['nullable', 'string', 'max:20'],
            'syllabus' => ['nullable', 'string', 'max:80'],
            'course' => ['nullable', 'string', 'max:80'],
            'school' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'source_group' => ['nullable', 'string', Rule::in(['influence', 'performance', 'albedo', 'reference', 'other'])],
            'source_code' => ['nullable', 'string', 'max:40'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high'])],
        ]);

        // Standardize phone number format
        try {
            $data['phone'] = \App\Services\PhoneNormalizer::normalize($data['phone']);
        } catch (\Throwable $e) {}

        // Check if lead already exists
        $existing = Lead::where('phone', $data['phone'])->first();
        if ($existing) {
            return response()->json([
                'message' => 'LEAD_ALREADY_EXISTS',
                'phone' => $data['phone']
            ], 409);
        }

        $stageId = LeadStage::where('key', 'new_lead')->value('id');

        $data['stage_id'] = $stageId;
        $data['status'] = 'New';
        $data['created_by'] = $user->id;
        $data['generated_by_user_id'] = $user->id;
        $data['assigned_dept'] = 'MARKETING';

        $lead = DB::transaction(function() use ($data) {
            return Lead::create($data);
        });

        return response()->json([
            'message' => 'LEAD_CREATE_SUCCESSFUL',
            'lead' => $lead
        ], 210); // Matches frontend expect values
    }

    /**
     * Edit imported lead properties
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        $lead = Lead::findOrFail($id);

        if ($roleKey === 'marketer' && $lead->generated_by_user_id !== $user->id && $lead->created_by !== $user->id) {
            return response()->json(['message' => 'UNAUTHORIZED_LEAD_ACCESS'], 403);
        }

        $data = $request->validate([
            'student_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:20'],
            'alternate_phone' => ['nullable', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:160'],
            'parent_name' => ['nullable', 'string', 'max:160'],
            'parent_relation' => ['nullable', 'string', Rule::in(['father', 'mother', 'guardian'])],
            'class' => ['nullable', 'string', 'max:20'],
            'syllabus' => ['nullable', 'string', 'max:80'],
            'course' => ['nullable', 'string', 'max:80'],
            'school' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'source_group' => ['nullable', 'string', Rule::in(['influence', 'performance', 'albedo', 'reference', 'other'])],
            'source_code' => ['nullable', 'string', 'max:40'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high'])],
        ]);

        $data['updated_by'] = $user->id;

        DB::transaction(function() use ($lead, $data) {
            $lead->update($data);
        });

        return response()->json([
            'message' => 'LEAD_UPDATE_SUCCESSFUL',
            'lead' => $lead->fresh()
        ]);
    }

    /**
     * Soft delete a single imported lead and move to marketing recycle bin
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        $lead = Lead::findOrFail($id);

        if ($roleKey === 'marketer' && $lead->generated_by_user_id !== $user->id && $lead->created_by !== $user->id) {
            return response()->json(['message' => 'UNAUTHORIZED_LEAD_DELETE'], 403);
        }

        DB::transaction(function() use ($lead, $user) {
            // First save who deleted it
            $lead->update([
                'deleted_by' => $user->id
            ]);
            // Perform soft delete
            $lead->delete();
        });

        return response()->json(['message' => 'LEAD_SOFT_DELETE_SUCCESSFUL']);
    }

    /**
     * Bulk Soft Delete leads
     */
    public function bulkDelete(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        $request->validate([
            'lead_ids' => ['required', 'array'],
            'lead_ids.*' => ['required', 'integer']
        ]);

        $leadIds = $request->input('lead_ids');

        DB::transaction(function() use ($leadIds, $user, $roleKey) {
            $leadsQuery = Lead::whereIn('id', $leadIds);
            if ($roleKey === 'marketer') {
                $leadsQuery->where(function($q) use ($user) {
                    $q->where('generated_by_user_id', $user->id)
                      ->orWhere('created_by', $user->id);
                });
            }

            $leads = $leadsQuery->get();
            foreach ($leads as $lead) {
                $lead->update(['deleted_by' => $user->id]);
                $lead->delete();
            }
        });

        return response()->json(['message' => 'BULK_SOFT_DELETE_SUCCESSFUL']);
    }



    /**
     * Get soft deleted marketing leads for the Marketing Recycle Bin
     */
    public function recycleBinIndex(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        $stageId = LeadStage::where('key', 'new_lead')->value('id');

        $query = Lead::onlyTrashed()
            ->where('stage_id', $stageId)
            ->with(['campaign']);

        if ($roleKey === 'marketer') {
            $query->where('deleted_by', $user->id);
        }

        if ($request->filled('q')) {
            $needle = trim((string) $request->string('q'));
            if ($needle !== '') {
                $query->where(function ($sq) use ($needle) {
                    $sq->where('student_name', 'like', '%'.$needle.'%')
                       ->orWhere('phone', 'like', '%'.$needle.'%');
                });
            }
        }

        $query->orderByDesc('deleted_at');

        $leads = $query->get()->map(function($l) {
            $deleter = User::find($l->deleted_by);
            $deleterName = $deleter ? trim($deleter->first_name . ' ' . $deleter->last_name) : 'System';
            return [
                'id' => $l->id,
                'name' => $l->student_name ?: 'Anonymous',
                'phone' => $l->phone,
                'campaign' => $l->campaign ? $l->campaign->name : ($l->campaign ?: '-'),
                'source' => $l->source_code ?: ($l->source_group ?: 'Direct'),
                'deleted_by' => $deleterName,
                'deleted_at' => $l->deleted_at?->toIso8601String()
            ];
        });

        return response()->json($leads);
    }

    /**
     * Restore a soft deleted imported lead
     */
    public function restore(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        $request->validate([
            'lead_ids' => ['required', 'array'],
            'lead_ids.*' => ['required', 'integer']
        ]);

        $leadIds = $request->input('lead_ids');

        DB::transaction(function() use ($leadIds, $user, $roleKey) {
            $leadsQuery = Lead::onlyTrashed()->whereIn('id', $leadIds);
            if ($roleKey === 'marketer') {
                $leadsQuery->where('deleted_by', $user->id);
            }

            $leads = $leadsQuery->get();
            foreach ($leads as $lead) {
                // Clear delete auditing details
                $lead->deleted_by = null;
                $lead->save();
                // Restore Eloquent record
                $lead->restore();
            }
        });

        return response()->json(['message' => 'LEAD_RESTORE_SUCCESSFUL']);
    }

    /**
     * Force delete a single lead (Super Admin / Admin only)
     */
    public function forceDelete(Request $request, $id)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        if (!in_array($roleKey, ['super_admin', 'admin'])) {
            return response()->json(['message' => 'UNAUTHORIZED_FORCE_DELETE'], 403);
        }

        $lead = Lead::withTrashed()->findOrFail($id);

        DB::transaction(function() use ($lead) {
            $lead->forceDelete();
        });

        return response()->json(['message' => 'LEAD_PERMANENT_DELETE_SUCCESSFUL']);
    }

    /**
     * Get list of assigned leads for marketer history view
     */
    public function assignedHistory(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        $query = Lead::query()
            ->whereNotNull('owner_id')
            ->with(['stage', 'campaign', 'owner']);

        // Marketer role filter: only own created/generated leads
        if ($roleKey === 'marketer') {
            $query->where(function($q) use ($user) {
                $q->where('generated_by_user_id', $user->id)
                  ->orWhere('created_by', $user->id);
            });
        } elseif ($roleKey === 'dept_head') {
            $dept = $user->department;
            if ($dept === 'PM') {
                $query->where('source_group', 'performance');
            } elseif ($dept === 'IM') {
                $query->where('source_group', 'influence');
            }
        }

        if ($request->filled('q')) {
            $needle = trim((string) $request->string('q'));
            if ($needle !== '') {
                $query->where(function ($sq) use ($needle) {
                    $sq->where('student_name', 'like', '%'.$needle.'%')
                       ->orWhere('phone', 'like', '%'.$needle.'%')
                       ->orWhere('email', 'like', '%'.$needle.'%');
                });
            }
        }

        $query->orderByDesc('assigned_at');

        $perPage = min(100, $request->integer('per_page', 50));
        return response()->json($query->paginate($perPage));
    }
}
