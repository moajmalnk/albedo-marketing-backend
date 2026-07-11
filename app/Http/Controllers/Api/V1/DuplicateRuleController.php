<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DuplicateRule;
use Illuminate\Http\Request;

class DuplicateRuleController extends Controller
{
    public function show()
    {
        $rule = DuplicateRule::first();
        
        if (!$rule) {
            $rule = DuplicateRule::create([
                'match_phone' => true,
                'match_email' => true,
                'match_whatsapp' => false,
                'default_action' => 'skip_report',
                'reengagement_rule' => 'do_nothing',
            ]);
        }
        
        return response()->json($rule);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'match_phone' => 'boolean',
            'match_email' => 'boolean',
            'match_whatsapp' => 'boolean',
            'default_action' => 'string|in:skip_report,merge_update,overwrite,create_duplicate',
            'reengagement_rule' => 'string|in:do_nothing,re_open',
        ]);

        $rule = DuplicateRule::first();
        if (!$rule) {
            $rule = DuplicateRule::create($validated);
        } else {
            $rule->update($validated);
        }

        return response()->json($rule);
    }

    public function logs()
    {
        $dupReasonId = \App\Models\LeadClosedReason::where('key', 'duplicate')->value('id');
        $duplicateStageId = \App\Models\LeadStage::where('key', 'duplicate_lead')->value('id');

        $logs = \App\Models\Lead::query()
            ->where(function($q) use ($dupReasonId, $duplicateStageId) {
                if ($dupReasonId) {
                    $q->where('closed_reason_id', $dupReasonId);
                }
                if ($duplicateStageId) {
                    $q->orWhere('stage_id', $duplicateStageId);
                }
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(function($l) {
                return [
                    'id' => $l->id,
                    'date' => $l->created_at?->toIso8601String(),
                    'rule' => $l->email ? 'Email/Phone Match' : 'Phone Match',
                    'action' => 'Skipped & Flagged',
                    'leadName' => $l->student_name ?: 'Anonymous',
                    'source' => $l->source_code ?: 'Unknown Source',
                ];
            });

        return response()->json($logs);
    }
}
