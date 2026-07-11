<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\LeadActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadRecycleController extends Controller
{
    /**
     * Get eligible leads and metrics
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Find inactive stages that are eligible for recycling
        // We exclude 'invalid_junk', 'duplicate_lead', 'job_enquiry'
        $excludedStages = ['invalid_junk', 'duplicate_lead', 'job_enquiry'];
        $eligibleStageIds = LeadStage::where('group', 'inactive')
            ->whereNotIn('key', $excludedStages)
            ->pluck('id');

        $query = Lead::whereIn('stage_id', $eligibleStageIds)
            ->with(['stage', 'owner.departments']);

        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        if ($roleKey === 'dept_head') {
            $dept = $user->department;
            $query->where(function ($q) use ($dept) {
                if (!empty($dept)) {
                    $expectedSourceGroup = ($dept === 'IM') ? 'influence' : (($dept === 'PM') ? 'performance' : null);
                    
                    $q->where('assigned_dept', $dept)
                      ->orWhereHas('owner', function($oq) use ($dept) {
                          $oq->where('department', $dept);
                      });
                      
                    if ($expectedSourceGroup) {
                        $q->orWhere(function ($uq) use ($expectedSourceGroup) {
                            $uq->whereNull('owner_id')
                               ->where('source_group', $expectedSourceGroup);
                        });
                    }
                } else {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $leads = $query->orderBy('score', 'desc')->get();

        // Metrics
        $reactivatedToday = LeadActivity::where('type', 'recycled')
            ->whereDate('created_at', today())
            ->count();

        // We can just return leads and metrics
        return response()->json([
            'metrics' => [
                'eligible' => $leads->count(),
                'recycledToday' => $reactivatedToday,
                'reactivated' => $reactivatedToday, // Simplified for now
                'conversionRate' => 'N/A' // Need advanced tracking for this
            ],
            'leads' => $leads->map(function ($lead) {
                $ownerName = 'Unassigned';
                if ($lead->owner) {
                    $ownerName = trim(($lead->owner->first_name ?? '') . ' ' . ($lead->owner->last_name ?? ''));
                    if ($ownerName === '') {
                        $ownerName = $lead->owner->email ?? 'Unknown User';
                    }
                }
                return [
                    'id' => $lead->id,
                    'name' => $lead->student_name ?: 'Anonymous',
                    'lastContact' => $lead->updated_at ? $lead->updated_at->format('M d, Y') : 'Never',
                    'source' => $lead->source_code ?: ($lead->source_group ?: 'Unknown'),
                    'prevOwner' => $ownerName,
                    'status' => $lead->stage ? $lead->stage->label : 'Inactive',
                    'score' => $lead->score ?? 0,
                    'department' => $lead->owner && $lead->owner->departments->count() > 0 
                        ? $lead->owner->departments->first()->name 
                        : null
                ];
            })
        ]);
    }

    /**
     * Auto-recycle a batch of leads
     */
    public function autoRecycle(Request $request)
    {
        $batchSize = $request->input('batchSize', 50);
        $user = $request->user();

        $excludedStages = ['invalid_junk', 'duplicate_lead', 'job_enquiry'];
        $eligibleStageIds = LeadStage::where('group', 'inactive')
            ->whereNotIn('key', $excludedStages)
            ->pluck('id');

        $query = Lead::whereIn('stage_id', $eligibleStageIds);

        $user->loadMissing('role');
        $roleKey = $user->role?->key ?? '';

        if ($roleKey === 'dept_head') {
            $dept = $user->department;
            $query->where(function ($q) use ($dept) {
                if (!empty($dept)) {
                    $expectedSourceGroup = ($dept === 'IM') ? 'influence' : (($dept === 'PM') ? 'performance' : null);
                    
                    $q->where('assigned_dept', $dept)
                      ->orWhereHas('owner', function($oq) use ($dept) {
                          $oq->where('department', $dept);
                      });
                      
                    if ($expectedSourceGroup) {
                        $q->orWhere(function ($uq) use ($expectedSourceGroup) {
                            $uq->whereNull('owner_id')
                               ->where('source_group', $expectedSourceGroup);
                        });
                    }
                } else {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $leadsToRecycle = $query->orderBy('score', 'desc')->take($batchSize)->get();
        $newLeadStage = LeadStage::where('key', 'new_lead')->first();

        DB::transaction(function() use ($leadsToRecycle, $newLeadStage, $user) {
            foreach ($leadsToRecycle as $lead) {
                $oldStage = $lead->stage_id;
                
                // Unassign owner and set stage to New Lead
                $lead->stage_id = $newLeadStage->id;
                $lead->owner_id = null; 
                $lead->save();

                LeadActivity::create([
                    'lead_id' => $lead->id,
                    'user_id' => $user->id,
                    'type' => 'recycled',
                    'description' => "Lead recycled from inactive queue by {$user->name}",
                ]);
            }
        });

        return response()->json(['message' => "Successfully recycled {$leadsToRecycle->count()} leads"]);
    }

    /**
     * Recycle a single lead
     */
    public function recycleSingle(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);
        $newLeadStage = LeadStage::where('key', 'new_lead')->first();
        $user = $request->user();

        $lead->stage_id = $newLeadStage->id;
        $lead->owner_id = null;
        $lead->save();

        LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'type' => 'recycled',
            'description' => "Lead recycled from inactive queue by {$user->name}",
        ]);

        return response()->json(['message' => 'Lead successfully recycled']);
    }

    /**
     * Archive a lead so it won't be recycled
     */
    public function archive(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);
        $archiveStage = LeadStage::where('key', 'invalid_junk')->first();
        $user = $request->user();

        $lead->stage_id = $archiveStage->id;
        $lead->save();

        LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'type' => 'stage_change',
            'description' => "Lead permanently archived by {$user->name}",
        ]);

        return response()->json(['message' => 'Lead permanently archived']);
    }
}
