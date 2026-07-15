<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadClosedReason;
use App\Models\LeadStage;
use App\Models\LeadStageAutomation;
use App\Models\LeadStagePermission;
use App\Models\LeadStageRequiredField;
use App\Models\LeadStageRule;
use App\Models\LeadStageTransition;
use App\Models\Task;
use App\Models\User;
use App\Services\LeadService;
use App\Services\SalesOwnerAssignmentService;
use App\Support\LeadChannelClassifier;
use App\Support\LeadFormPicklist;
use Database\Seeders\LeadStageSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /**
     * @return list<\Illuminate\Contracts\Validation\Rule|string>
     */
    private function picklistValueRules(string $groupSlug, int $maxLen = 191): array
    {
        $allowed = LeadFormPicklist::activeValuesForSlug($groupSlug);
        $rules = ['nullable', 'string', 'max:'.$maxLen];
        if ($allowed !== []) {
            $rules[] = Rule::in($allowed);
        }

        return $rules;
    }

    /**
     * @return list<\Illuminate\Contracts\Validation\Rule|string>
     */
    private function picklistArrayItemRules(string $groupSlug, int $maxLen): array
    {
        $allowed = LeadFormPicklist::activeValuesForSlug($groupSlug);
        $rules = ['string', 'max:'.$maxLen];
        if ($allowed !== []) {
            $rules[] = Rule::in($allowed);
        }

        return $rules;
    }

    public function kpis(Request $request)
    {
        $baseQuery = Lead::query();
        
        $total = (clone $baseQuery)->count();
        $unassigned = (clone $baseQuery)->whereNull('owner_id')->count();
        
        $qualified = (clone $baseQuery)->whereHas('stage', function($q) {
            $q->where('key', 'qualified');
        })->count();
        
        $lostToday = (clone $baseQuery)->whereHas('stage', function($q) {
            $q->where('key', 'closed_lost');
        })->whereDate('updated_at', now()->toDateString())->count();
        
        return response()->json([
            'total' => $total,
            'unassigned' => $unassigned,
            'qualified' => $qualified,
            'lost_today' => $lostToday,
            'overdue' => 0
        ]);
    }

    public function index(Request $request)
    {
        $request->validate([
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'assigned_dept' => ['nullable', 'string', Rule::in(['SALES', 'MARKETING'])],
            'country' => ['nullable', 'string', 'max:80'],
            'created_by' => ['nullable', 'integer'],
            'generated_by_user_id' => ['nullable', 'integer'],
            'attributed_to_user_id' => ['nullable', 'integer'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'status_filter' => ['nullable', 'string', 'max:100'],
            'status_filters' => ['nullable'],
            'smart_view' => ['nullable', 'string', 'max:50'],
            'channel' => ['nullable', 'string', 'max:50'],
            'platform' => ['nullable', 'string', 'max:50'],
            'sub_brand' => ['nullable', 'string', 'max:80'],
            'source_group' => ['nullable', 'string', Rule::in(['influence', 'performance', 'albedo', 'reference', 'other'])],
            'month' => ['nullable', 'string'],
            'year' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:120'],
            'telecaller_owner_id' => ['nullable', 'integer'],
            'psa_owner_id' => ['nullable', 'integer'],
            'advisor_owner_id' => ['nullable', 'integer'],
            'source_codes' => ['nullable', 'string', 'max:500'],
            'course' => ['nullable', 'string', 'max:120'],
            'syllabus' => ['nullable', 'string', 'max:80'],
            'class' => ['nullable', 'string', 'max:40'],
            'state' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:80'],
            'device' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', 'string', 'max:40'],
            'assessment_status' => ['nullable', 'string', 'max:80'],
            'assigned_only' => ['nullable', 'boolean'],
        ]);

        $query = Lead::query()->with([
            'stage',
            'closedReason',
            'closedBy:id,first_name,last_name,email',
            'owner:id,first_name,last_name,email,role_id,department',
            'owner.role:id,key,name',
            'telecallerOwner:id,first_name,last_name,email,role_id',
            'psaOwner:id,first_name,last_name,email,role_id',
            'advisorOwner:id,first_name,last_name,email,role_id',
        ]);

        if ($request->boolean('trashed')) {
            $user = $request->user();
            if (in_array($user?->role?->key, ['super_admin', 'admin'])) {
                $query->onlyTrashed();
            }
        }

        // Role-Based Access Control (RBAC) implementation
        $user = $request->user();
        if ($user) {
            $user->loadMissing('role');
            $roleKey = $user->role?->key ?? '';

            if (in_array($roleKey, ['telecaller', 'psa', 'advisor'], true)) {
                $query->where('owner_id', $user->id);
            } elseif ($roleKey === 'sales_head') {
                $visibleStageIds = \App\Models\LeadStagePermission::where('role', 'sales_head')
                    ->where('can_view', true)
                    ->pluck('lead_stage_id');

                // Sales-created and telecaller-handoff leads land on `qualified`. Always include it
                // even if stage-permission rows were never migrated/updated.
                $qualifiedStageId = LeadStage::query()->where('key', 'qualified')->value('id');
                if ($qualifiedStageId) {
                    $visibleStageIds = $visibleStageIds->push($qualifiedStageId)->unique()->values();
                }

                $query->whereIn('stage_id', $visibleStageIds);
            } elseif ($roleKey === 'dept_head') {
                $dept = $user->department;
                $query->where(function (Builder $q) use ($dept) {
                    if (! empty($dept)) {
                        $expectedSourceGroup = ($dept === 'IM') ? 'influence' : (($dept === 'PM') ? 'performance' : null);

                        $q->where('assigned_dept', $dept)
                            ->orWhereHas('owner', function ($oq) use ($dept) {
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
            } elseif ($roleKey === 'marketer') {
                $query->where(function (Builder $q) use ($user) {
                    $q->where('generated_by_user_id', $user->id)
                        ->orWhereNull('owner_id');
                });
            }
        }
        if ($request->filled('q')) {
            $needle = trim((string) $request->string('q'));
            if ($needle !== '') {
                $query->where(function (Builder $sq) use ($needle) {
                    $sq->where('student_name', 'like', '%'.$needle.'%')
                        ->orWhere('phone', 'like', '%'.$needle.'%')
                        ->orWhere('email', 'like', '%'.$needle.'%');
                    if (preg_match('/^\d+$/', $needle)) {
                        $sq->orWhere('id', (int) $needle);
                    }
                });
            }
        }
        if ($request->filled('stage')) {
            $query->whereHas('stage', fn ($q) => $q->where('key', $request->string('stage')));
        }
        if ($request->filled('source_code')) {
            $query->where('source_code', $request->string('source_code'));
        }
        if ($request->filled('owner_id')) {
            $query->where('owner_id', (int) $request->input('owner_id'));
        }
        if ($request->filled('telecaller_owner_id')) {
            $query->where('telecaller_owner_id', (int) $request->input('telecaller_owner_id'));
        }
        if ($request->filled('psa_owner_id')) {
            $query->where('psa_owner_id', (int) $request->input('psa_owner_id'));
        }
        if ($request->filled('advisor_owner_id')) {
            $query->where('advisor_owner_id', (int) $request->input('advisor_owner_id'));
        }
        if ($request->boolean('unassigned')) {
            $query->whereNull('owner_id');
        }
        if ($request->boolean('assigned_only')) {
            $query->whereNotNull('owner_id');
        }
        if ($request->filled('source_codes')) {
            $codes = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('source_codes')))));
            if ($codes !== []) {
                $query->whereIn('source_code', $codes);
            }
        }
        if ($request->filled('course')) {
            $query->where('course', $request->string('course'));
        }
        if ($request->filled('syllabus')) {
            $query->where('syllabus', $request->string('syllabus'));
        }
        if ($request->filled('class')) {
            $query->where('class', $request->string('class'));
        }
        if ($request->filled('state')) {
            $query->where('state', $request->string('state'));
        }
        if ($request->filled('district')) {
            $query->where(function (Builder $q) use ($request) {
                $district = (string) $request->string('district');
                $q->where('district', $district)->orWhere('city', $district);
            });
        }
        if ($request->filled('campaign')) {
            $campaign = (string) $request->string('campaign');
            $query->where(function (Builder $q) use ($campaign) {
                $q->where('campaign', $campaign)
                    ->orWhere('campaign', 'like', '%'.$campaign.'%');
            });
        }
        if ($request->filled('utm_medium')) {
            // utm_medium is not a first-class column; match campaign/connected_by loosely
            $medium = (string) $request->string('utm_medium');
            $query->where(function (Builder $q) use ($medium) {
                $q->where('campaign', 'like', '%'.$medium.'%')
                    ->orWhere('connected_by', 'like', '%'.$medium.'%');
            });
        }
        if ($request->filled('device')) {
            // device is not always a DB column — soft-match notes/campaign when present
            $device = (string) $request->string('device');
            $query->where(function (Builder $q) use ($device) {
                $q->where('connected_by', $device)
                    ->orWhere('campaign', 'like', '%'.$device.'%');
            });
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }
        if ($request->filled('assessment_status')) {
            // assessment status lives in related activity/qualification fields when present
            $assessment = (string) $request->string('assessment_status');
            $query->where(function (Builder $q) use ($assessment) {
                $q->where('status', $assessment)
                    ->orWhereHas('stage', fn ($sq) => $sq->where('label', $assessment));
            });
        }
        if ($request->filled('created_from')) {
            $query->whereDate('created_at', '>=', $request->date('created_from')->toDateString());
        }
        if ($request->filled('created_to')) {
            $query->whereDate('created_at', '<=', $request->date('created_to')->toDateString());
        }
        if ($request->filled('assigned_dept')) {
            $query->where('assigned_dept', $request->string('assigned_dept'));
        }
        if ($request->filled('source_group')) {
            $query->where('source_group', $request->string('source_group'));
        }
        $platform = $request->input('platform', 'All');
        if ($platform !== 'All' && is_string($platform) && $platform !== '') {
            $sourceGroupAlias = match (strtolower($platform)) {
                'website' => 'albedo',
                'other' => 'other',
                'influence', 'performance', 'albedo', 'reference' => strtolower($platform),
                default => null,
            };
            $query->where(function (Builder $q) use ($platform, $sourceGroupAlias) {
                $q->whereHas('campaign', fn (Builder $cq) => $cq->where('platform', $platform))
                    ->orWhere('source_group', $platform)
                    ->orWhere('campaign', 'like', '%'.$platform.'%')
                    ->orWhere('connected_by', $platform);
                if ($sourceGroupAlias) {
                    $q->orWhere('source_group', $sourceGroupAlias);
                }
            });
        }
        $subBrand = $request->input('sub_brand', 'All');
        if ($subBrand !== 'All' && is_string($subBrand) && $subBrand !== '') {
            $query->where(function (Builder $q) use ($subBrand) {
                $q->whereHas('owner', fn (Builder $oq) => $oq->where('sub_brand', $subBrand))
                    ->orWhereHas('generatedBy', fn (Builder $gq) => $gq->where('sub_brand', $subBrand));
            });
        }
        if ($request->filled('country')) {
            $location = (string) $request->string('country');
            $query->where(function (Builder $q) use ($location) {
                $q->where('country', $location)
                    ->orWhere('state', $location)
                    ->orWhere('district', $location)
                    ->orWhere('city', $location);
            });
        }
        if ($request->filled('created_by')) {
            $query->where('created_by', (int) $request->input('created_by'));
        }
        if ($request->filled('generated_by_user_id')) {
            $query->where('generated_by_user_id', (int) $request->input('generated_by_user_id'));
        }
        if ($request->filled('attributed_to_user_id')) {
            $uid = (int) $request->input('attributed_to_user_id');
            $query->where(function (Builder $q) use ($uid) {
                $q->where('generated_by_user_id', $uid)->orWhere('created_by', $uid);
            });
        }
        if ($request->filled('manager_id')) {
            $mid = (int) $request->input('manager_id');
            $ownerIds = User::query()
                ->where('reporting_manager_id', $mid)
                ->whereHas('role', fn (Builder $q) => $q->where('key', 'telecaller'))
                ->pluck('id');
            if ($ownerIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('owner_id', $ownerIds);
            }
        }
        $statusLabels = $this->resolveStatusFilterLabels($request);
        if ($statusLabels !== []) {
            $this->applyLeadStatusFilter($query, $statusLabels);
        }
        $channel = $request->input('channel', 'All');
        if ($channel !== 'All' && is_string($channel)) {
            LeadChannelClassifier::applyChannelFilter($query, $channel);
        }

        $smartView = $request->input('smart_view', 'All');
        if ($smartView === 'needs_assignment') {
            $query->whereNull('owner_id');
        } elseif ($smartView === 'assigned') {
            // All leads that have an owner (management "Assigned" tab)
            $query->whereNotNull('owner_id');
        } elseif ($smartView === 'my_assigned' && $user) {
            $query->where('owner_id', $user->id);
        } elseif ($smartView === 'qualified') {
            $query->whereHas('stage', fn($q) => $q->where('key', 'qualified'));
        } elseif ($smartView === 'recovery') {
            $query->whereHas('stage', fn($q) => $q->whereIn('key', [
                'recovery_required',
                'psa_recovery',
                'returned_to_advisor',
            ]));
        }
        $month = $request->input('month');
        if ($month !== null && $month !== '' && $month !== 'All') {
            $m = (int) $month;
            if ($m >= 1 && $m <= 12) {
                $query->whereMonth('created_at', $m);
            }
        }
        $year = $request->input('year');
        if ($year !== null && $year !== '' && $year !== 'All') {
            $y = (int) $year;
            if ($y >= 2000 && $y <= 2100) {
                $query->whereYear('created_at', $y);
            }
        }

        $sort = (string) $request->input('sort', '-created_at');
        if ($sort === '-created_at') {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } elseif ($sort === 'created_at') {
            $query->orderBy('created_at')->orderBy('id');
        } else {
            $query->latest('id');
        }

        $perPage = (int) $request->input('limit', 20);
        $perPage = max(1, min(100, $perPage));

        return response()->json($query->paginate($perPage));
    }

    /**
     * Resolve stage labels from status_filters (multi) or legacy status_filter (single).
     *
     * @return list<string>
     */
    private function resolveStatusFilterLabels(Request $request): array
    {
        $raw = $request->input('status_filters');
        $labels = [];

        if (is_array($raw)) {
            $labels = $raw;
        } elseif (is_string($raw) && trim($raw) !== '' && strcasecmp($raw, 'All') !== 0) {
            $labels = array_map('trim', explode(',', $raw));
        } else {
            $legacy = $request->input('status_filter', 'All');
            if (is_string($legacy) && $legacy !== '' && strcasecmp($legacy, 'All') !== 0) {
                $labels = [$legacy];
            }
        }

        $labels = array_values(array_unique(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $labels),
            static fn ($v) => $v !== '' && strcasecmp($v, 'All') !== 0
        )));

        return $labels;
    }

    /**
     * @param  list<string>  $statusLabels
     */
    private function applyLeadStatusFilter(Builder $query, array $statusLabels): void
    {
        // UI sends exact stage labels (e.g. "Assigned to Telecaller", "Qualified", "Lost")
        $query->whereHas('stage', fn ($q) => $q->whereIn('label', $statusLabels));
    }

    private function stagePermission(?int $stageId, string $roleKey): ?LeadStagePermission
    {
        if (! $stageId) {
            return null;
        }

        return LeadStagePermission::query()
            ->where('lead_stage_id', $stageId)
            ->where('role', $roleKey)
            ->first();
    }

    private function defaultStagePermission(string $roleKey, ?LeadStage $stage = null): array
    {
        $isLeader = in_array($roleKey, ['super_admin', 'admin', 'sales_head', 'department_head'], true);
        $isOwnerRole = $stage && $stage->owner_role === $roleKey;

        return [
            'can_view' => true,
            'can_move' => $isLeader || $isOwnerRole,
            'can_override' => $isLeader,
            'can_close' => $isLeader || $isOwnerRole,
            'can_reopen' => $isLeader,
            'can_delete' => in_array($roleKey, ['super_admin', 'admin'], true),
        ];
    }

    private function stagePermissionValue(?LeadStagePermission $permission, string $key, string $roleKey, ?LeadStage $stage = null): bool
    {
        if ($permission) {
            return (bool) $permission->{$key};
        }

        return (bool) $this->defaultStagePermission($roleKey, $stage)[$key];
    }

    public function store(Request $request, LeadService $leadService)
    {
        $data = $request->validate([
            'capture_qualification' => ['nullable', 'string', Rule::in(['qualified', 'not_qualified'])],
            'student_name' => [
                Rule::requiredIf(fn () => ($request->input('capture_qualification') ?? 'qualified') !== 'not_qualified'),
                'nullable',
                'string',
                'max:160',
            ],
            'phone' => ['required', 'string', 'max:20'],
            'alternate_phone' => ['nullable', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:160'],
            'children_count' => ['nullable', 'integer', 'min:0', 'max:30'],
            'already_enrolled' => ['nullable', 'boolean'],
            'parent_name' => ['nullable', 'string', 'max:160'],
            'parent_relation' => ['nullable', 'string', Rule::in(['father', 'mother', 'guardian'])],
            'class' => ['nullable', 'string', 'max:20'],
            'syllabus' => $this->picklistValueRules('syllabus'),
            'course' => $this->picklistValueRules('course'),
            'subjects' => ['nullable', 'array'],
            'subjects.*' => $this->picklistArrayItemRules('subject', 120),
            'school' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'source_group' => ['nullable', 'string', Rule::in(['influence', 'performance', 'albedo', 'reference', 'other'])],
            'source_code' => ['nullable', 'string', 'max:40'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'connected_by' => ['nullable', 'string', 'max:64'],
            'enquiry_at' => ['nullable', 'date'],
            'notes_html' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', 'string', 'max:40'],
            'owner_id' => ['nullable', 'integer'],
            'generated_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_dept' => ['nullable', 'string', Rule::in(['SALES', 'MARKETING'])],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high'])],
            'dnd' => ['nullable', 'boolean'],
            'next_action_at' => ['nullable', 'date'],
        ]);

        foreach ($data as $key => $value) {
            if ($value === '' && ! in_array($key, ['student_name', 'phone'], true)) {
                unset($data[$key]);
            }
        }

        $data['capture_qualification'] = $data['capture_qualification'] ?? 'qualified';
        if ($data['capture_qualification'] === 'not_qualified') {
            $name = isset($data['student_name']) ? trim((string) $data['student_name']) : '';
            $data['student_name'] = $name === '' ? null : $name;
        }

        if (! empty($data['notes_html'])) {
            $data['notes_html'] = strip_tags(
                (string) $data['notes_html'],
                '<p><br><b><strong><i><em><u><ul><ol><li><a><span><div>'
            );
        }

        $data['created_by'] = $request->user()?->id;
        $data['generated_by_user_id'] = $data['generated_by_user_id'] ?? $request->user()?->id;

        // Sales-head creates skip telecaller stages — land at qualified (same as telecaller handoff).
        $creator = $request->user();
        $creator?->loadMissing('role');
        $initialStageKey = ($creator?->role?->key === 'sales_head') ? 'qualified' : 'new_lead';
        $initialStage = LeadStage::query()->where('key', $initialStageKey)->first();
        if ($initialStage) {
            $data['stage_id'] = $initialStage->id;
            $data['status'] = $initialStage->legacy_status
                ?? ($initialStageKey === 'qualified' ? 'Qualified' : 'New');
        }

        return response()->json($leadService->createLead($data)->load(['stage', 'closedReason', 'closedBy:id,first_name,last_name,email', 'owner', 'generatedBy:id,first_name,last_name,email']), 201);
    }

    public function show(Lead $lead)
    {
        return response()->json($lead->load([
            'stage',
            'closedReason',
            'closedBy:id,first_name,last_name,email',
            'owner:id,first_name,last_name,email,role_id,department',
            'owner.role:id,key,name',
            'telecallerOwner:id,first_name,last_name,email,role_id',
            'psaOwner:id,first_name,last_name,email,role_id',
            'advisorOwner:id,first_name,last_name,email,role_id',
            'activities',
            'generatedBy:id,first_name,last_name,email',
        ]));
    }

    public function update(Request $request, Lead $lead)
    {
        if ($lead->is_read_only && ! $request->attributes->get('bypass_readonly')) {
            return response()->json(['message' => 'LEAD_IS_READ_ONLY'], 403);
        }

        $lead->update($request->all());
        return response()->json($lead->fresh([
            'stage',
            'closedReason',
            'closedBy:id,first_name,last_name,email',
            'owner:id,first_name,last_name,email,role_id,department',
            'owner.role:id,key,name',
            'telecallerOwner:id,first_name,last_name,email,role_id',
            'psaOwner:id,first_name,last_name,email,role_id',
            'advisorOwner:id,first_name,last_name,email,role_id',
        ]));
    }

    public function assign(Request $request, Lead $lead)
    {
        $data = $request->validate(['owner_id' => ['required', 'integer']]);
        $owner = User::query()->with('role')->findOrFail($data['owner_id']);
        $update = ['owner_id' => $owner->id];
        if ($owner->role?->key === 'telecaller') {
            $update['telecaller_owner_id'] = $owner->id;
        }
        $lead->update($update);
        return response()->json($lead->fresh(['stage', 'closedReason', 'closedBy:id,first_name,last_name,email', 'owner', 'telecallerOwner']));
    }

    public function changeStage(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'stage_key' => ['nullable', 'string'],
            'closed_reason_key' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
            'course_interested' => ['nullable', 'string'],
            'qualification' => ['nullable', 'string'],
            'preferred_campus' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $userRole = $user?->role?->key ?? 'telecaller';

        // A. Handle Closing the Lead via closed_reason_key. Closure is independent from the active pipeline.
        if (! empty($data['closed_reason_key'])) {
            $closedReason = LeadClosedReason::where('key', $data['closed_reason_key'])->first();
            if (! $closedReason) {
                return response()->json(['message' => 'Unknown closed_reason_key'], 422);
            }

            $currentStage = $lead->stage_id ? LeadStage::query()->find($lead->stage_id) : null;
            $permission = $this->stagePermission($lead->stage_id, $userRole);
            $canClose = $this->stagePermissionValue($permission, 'can_close', $userRole, $currentStage)
                || $this->stagePermissionValue($permission, 'can_override', $userRole, $currentStage);
            if (! $canClose) {
                return response()->json(['message' => 'Your role is not permitted to close this lead.'], 403);
            }

            $fromStageId = $lead->stage_id;
            $lead->update([
                'closed_reason_id' => $closedReason->id,
                'closed_by' => $user?->id,
                'closed_at' => now(),
                'is_read_only' => true,
                'status' => $closedReason->label,
                'owner_id' => null,
            ]);

            $transition = LeadStageTransition::create([
                'lead_id' => $lead->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $fromStageId,
                'reason' => 'Closed as: '.$closedReason->label.(! empty($data['reason']) ? ' ('.$data['reason'].')' : ''),
                'changed_by' => $user->id,
                'changed_at' => now(),
            ]);

            return response()->json(['lead' => $lead->fresh(['stage', 'closedReason', 'closedBy:id,first_name,last_name,email', 'owner']), 'transition' => $transition], 200);
        }

        // B. Handle Stage Change via stage_key
        $stageKey = $data['stage_key'] ?? null;
        if (! $stageKey) {
            return response()->json(['message' => 'stage_key or closed_reason_key required'], 422);
        }

        if ($lead->closed_reason_id) {
            $currentStage = $lead->stage_id ? LeadStage::query()->find($lead->stage_id) : null;
            $currentPermission = $this->stagePermission($lead->stage_id, $userRole);
            $canReopen = $this->stagePermissionValue($currentPermission, 'can_reopen', $userRole, $currentStage)
                || $this->stagePermissionValue($currentPermission, 'can_override', $userRole, $currentStage);
            if (! $canReopen) {
                return response()->json(['message' => 'Your role is not permitted to reopen this lead.'], 403);
            }
        }

        $targetStage = LeadStage::where('key', $stageKey)->first();
        if (! $targetStage) {
            (new LeadStageSeeder)->run();
            $targetStage = LeadStage::where('key', $stageKey)->first();
        }

        if (! $targetStage) {
            return response()->json(['message' => 'Unknown stage_key', 'stage_key' => $stageKey], 422);
        }

        // 1. Permission Check
        $permission = $this->stagePermission($targetStage->id, $userRole);

        $canMove = $this->stagePermissionValue($permission, 'can_move', $userRole, $targetStage)
            || $this->stagePermissionValue($permission, 'can_override', $userRole, $targetStage);
        $canOverride = $this->stagePermissionValue($permission, 'can_override', $userRole, $targetStage);
        if (! $canMove) {
            return response()->json([
                'message' => 'Your role ('.$userRole.') is not permitted to move leads to the stage "'.$targetStage->label.'".',
            ], 403);
        }

        // 2. Transition Rule Check
        if ($lead->stage_id && $lead->stage_id !== $targetStage->id && ! $canOverride) {
            $ruleExists = LeadStageRule::where('from_stage_id', $lead->stage_id)
                ->where('to_stage_id', $targetStage->id)
                ->where('is_active', true)
                ->exists();

            if (! $ruleExists) {
                $currentStageLabel = $lead->stage?->label ?? 'Current Stage';
                return response()->json([
                    'message' => 'Transition from "'.$currentStageLabel.'" to "'.$targetStage->label.'" is not allowed by pipeline configuration rules.',
                ], 422);
            }
        }

        // 3. Required Fields Check
        $requiredFields = LeadStageRequiredField::where('lead_stage_id', $targetStage->id)
            ->where('is_required', true)
            ->get();

        $missingFields = [];
        $fieldsToUpdate = [];
        foreach ($requiredFields as $req) {
            $val = $request->input($req->field_name, $lead->{$req->field_name});
            if (empty($val)) {
                $missingFields[] = $req->field_label;
            } else {
                $fieldsToUpdate[$req->field_name] = $val;
            }
        }

        // Also check if any additional fields were passed in request that exist in lead model and update them
        foreach (['course_interested', 'qualification', 'preferred_campus'] as $f) {
            if ($request->has($f)) {
                $fieldsToUpdate[$f] = $request->input($f);
            }
        }

        if (! empty($missingFields)) {
            return response()->json([
                'message' => 'Required fields missing for stage "'.$targetStage->label.'": '.implode(', ', $missingFields),
                'missing_fields' => $missingFields,
            ], 422);
        }

        // 4. Update Lead Stage
        $fromStageId = $lead->stage_id;
        $lead->update(array_merge([
            'stage_id' => $targetStage->id,
            'closed_reason_id' => null,
            'closed_by' => null,
            'closed_at' => null,
            'is_read_only' => false,
            'status' => $targetStage->legacy_status ?? 'Qualified',
        ], $fieldsToUpdate));

        $transition = LeadStageTransition::create([
            'lead_id' => $lead->id,
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $targetStage->id,
            'reason' => $data['reason'] ?? null,
            'changed_by' => $user->id,
            'changed_at' => now(),
        ]);

        // 5. Execute Stage Automations
        $automations = LeadStageAutomation::where('lead_stage_id', $targetStage->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($automations as $auto) {
            if ($auto->action === 'assign_role' && ! empty($auto->target_role)) {
                $candidate = User::query()
                    ->whereHas('role', fn ($q) => $q->where('key', $auto->target_role))
                    ->where('status', 'active')
                    ->first();
                if ($candidate) {
                    $lead->update(['owner_id' => $candidate->id]);
                }
            } elseif ($auto->action === 'create_task') {
                Task::create([
                    'title' => $auto->task_template ?? ('Follow up on '.$targetStage->label),
                    'lead_id' => $lead->id,
                    'assigned_to' => $lead->owner_id ?? $user->id,
                    'created_by' => $user->id,
                    'status' => 'pending',
                    'due_at' => now()->addHours($targetStage->sla_hours ?? 24),
                ]);
            }
        }

        return response()->json(['lead' => $lead->fresh(['stage', 'closedReason', 'closedBy:id,first_name,last_name,email', 'owner']), 'transition' => $transition], 200);
    }

    public function assignmentQueue(Request $request)
    {
        $query = Lead::query()
            ->where(function ($q) {
                $q->whereNull('owner_id')
                  ->orWhere('assignment_status', 'waiting');
            });

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('student_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($request->filled('source_code')) {
            $query->where('source_code', $request->input('source_code'));
        }
        if ($request->filled('campaign')) {
            $query->where('campaign', $request->input('campaign'));
        }
        if ($request->filled('assigned_dept')) {
            $query->where('assigned_dept', $request->input('assigned_dept'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }
        if ($request->filled('assignment_status')) {
            $query->where('assignment_status', $request->input('assignment_status'));
        }
        if ($request->filled('score')) {
            $query->where('score', '>=', (int)$request->input('score'));
        }

        // Sorting
        $sortField = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortField, $sortOrder);

        // Pagination
        $perPage = $request->integer('per_page', 20);
        $leads = $query->paginate($perPage);

        return response()->json($leads);
    }

    public function assignmentStats()
    {
        $today = now()->toDateString();
        
        $incomingToday = Lead::whereDate('created_at', $today)->count();
        $importedToday = Lead::whereDate('created_at', $today)->whereNotNull('generated_by_user_id')->count();
        $assignedToday = \App\Models\LeadAssignment::whereDate('created_at', $today)->count();
        $waitingAssignment = Lead::whereNull('owner_id')->count();
        $duplicateLeads = \App\Models\LeadImportRow::where('status', 'duplicate')->whereDate('created_at', $today)->count();

        return response()->json([
            'incoming_today' => $incomingToday,
            'imported_today' => $importedToday,
            'assigned_today' => $assignedToday,
            'waiting_assignment' => $waitingAssignment,
            'duplicate_leads' => $duplicateLeads,
        ]);
    }

    public function bulkAssign(Request $request)
    {
        $request->validate([
            'lead_ids' => ['required', 'array'],
            'lead_ids.*' => ['required', 'integer'],
            'telecaller_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'assignment_notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $telecallerId = $request->input('telecaller_id') ?? $request->input('owner_id');
        $notes = $request->input('assignment_notes') ?? $request->input('reason') ?? 'Manual Assignment';
        $assignedBy = auth()->id();

        $stageId = LeadStage::where('key', 'assigned_telecaller')->value('id');

        $newLeadStageId = LeadStage::where('key', 'new_lead')->value('id');

        \Illuminate\Support\Facades\DB::transaction(function() use ($request, $telecallerId, $notes, $assignedBy, $stageId, $newLeadStageId) {
            $leads = Lead::whereIn('id', $request->input('lead_ids'))->get();
            foreach ($leads as $lead) {
                if ($telecallerId) {
                    $lead->assignment_type = 'Manual Assignment';
                    $lead->assignment_reason = $notes;
                    $lead->update([
                        'owner_id' => $telecallerId,
                        'telecaller_owner_id' => $telecallerId,
                        'stage_id' => $stageId,
                        'status' => 'Assigned',
                        'assigned_dept' => 'SALES',
                        'assignment_status' => 'assigned',
                        'assigned_by' => $assignedBy,
                        'assigned_at' => now(),
                        'assignment_notes' => $notes,
                        'routing_failed' => false,
                    ]);
                } else {
                    $lead->assignment_type = 'Remove Assignment';
                    $lead->assignment_reason = $notes;
                    $lead->update([
                        'owner_id' => null,
                        'telecaller_owner_id' => null,
                        'stage_id' => $newLeadStageId,
                        'status' => 'New',
                        'assigned_dept' => 'MARKETING',
                        'assignment_status' => 'waiting',
                        'assigned_by' => null,
                        'assigned_at' => null,
                        'assignment_notes' => null,
                    ]);
                }
            }
        });

        return response()->json(['message' => 'BULK_ASSIGN_SUCCESSFUL']);
    }

    public function assignmentHistory(Request $request)
    {
        $query = \App\Models\LeadAssignmentLog::with([
            'lead:id,student_name,phone',
            'oldOwner:id,first_name,last_name',
            'newOwner:id,first_name,last_name',
            'assignedBy:id,first_name,last_name'
        ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('lead', function ($q) use ($search) {
                $q->where('student_name', 'like', "%{$search}%");
            });
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $request->integer('per_page', 20);
        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    public function distributionStats()
    {
        $today = now()->toDateString();

        $readyForPsa = Lead::whereHas('stage', fn($q) => $q->where('key', 'ready_for_psa'))
            ->whereNull('psa_owner_id')
            ->count();

        // Waiting PSA: ready_for_psa, psa_owner_id = NULL, waiting longer than SLA (24 hours)
        $waitingPsa = Lead::whereHas('stage', fn($q) => $q->where('key', 'ready_for_psa'))
            ->whereNull('psa_owner_id')
            ->where('updated_at', '<', now()->subHours(24))
            ->count();

        $assignedPsa = Lead::whereNotNull('psa_owner_id')
            ->whereNull('advisor_owner_id')
            ->count();

        $readyForAdvisor = Lead::whereHas('stage', fn($q) => $q->where('key', 'ready_for_advisor'))
            ->whereNull('advisor_owner_id')
            ->count();

        $assignedAdvisor = Lead::whereNotNull('advisor_owner_id')->count();

        // Reassignments performed today (assignment logs of type manual where old owner was not null)
        $reassignedToday = \App\Models\LeadAssignment::whereDate('created_at', $today)
            ->whereNotNull('previous_owner_id')
            ->count();

        return response()->json([
            'ready_for_psa' => $readyForPsa,
            'waiting_psa' => $waitingPsa,
            'assigned_psa' => $assignedPsa,
            'ready_for_advisor' => $readyForAdvisor,
            'assigned_advisor' => $assignedAdvisor,
            'reassigned_today' => $reassignedToday
        ]);
    }

    public function psaQueue(Request $request)
    {
        $query = Lead::query()
            ->with(['owner'])
            ->whereHas('stage', fn($q) => $q->where('key', 'ready_for_psa'))
            ->whereNull('psa_owner_id');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('student_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('assigned_dept')) {
            $query->where('assigned_dept', $request->input('assigned_dept'));
        }

        $perPage = $request->integer('per_page', 20);
        return response()->json($query->paginate($perPage));
    }

    public function advisorQueue(Request $request)
    {
        $query = Lead::query()
            ->with(['psaOwner'])
            ->whereHas('stage', fn($q) => $q->where('key', 'ready_for_advisor'))
            ->whereNull('advisor_owner_id');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('student_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        $perPage = $request->integer('per_page', 20);
        return response()->json($query->paginate($perPage));
    }

    public function bulkAssignPsa(Request $request)
    {
        $request->validate([
            'lead_ids' => ['required', 'array'],
            'lead_ids.*' => ['required', 'integer', 'exists:leads,id'],
            'psa_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:255']
        ]);

        $psa = User::find($request->input('psa_id'));
        $leads = Lead::whereIn('id', $request->input('lead_ids'))->get();
        $actor = $request->user();

        foreach ($leads as $lead) {
            // Validation: Must be in ready_for_psa stage
            $stage = LeadStage::find($lead->stage_id);
            if (!$stage || $stage->key !== 'ready_for_psa') {
                return response()->json(['message' => 'INVALID_LEAD_STAGE', 'lead_id' => $lead->id], 422);
            }

            $oldOwnerId = $lead->owner_id;

            $lead->assignment_type = 'Recovery Assignment';
            $lead->assignment_reason = $request->input('reason') ?? 'PSA Lead Distribution';

            $lead->update([
                'owner_id' => $psa->id,
                'psa_owner_id' => $psa->id,
                'assignment_status' => 'assigned'
            ]);
        }

        return response()->json(['message' => 'PSA_ASSIGN_SUCCESSFUL']);
    }

    public function bulkAssignAdvisor(Request $request)
    {
        $request->validate([
            'lead_ids' => ['required', 'array'],
            'lead_ids.*' => ['required', 'integer', 'exists:leads,id'],
            'advisor_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:255']
        ]);

        $advisor = User::find($request->input('advisor_id'));
        $leads = Lead::whereIn('id', $request->input('lead_ids'))->get();
        $actor = $request->user();

        foreach ($leads as $lead) {
            // Validation: Must be in ready_for_advisor stage
            $stage = LeadStage::find($lead->stage_id);
            if (!$stage || $stage->key !== 'ready_for_advisor') {
                return response()->json(['message' => 'INVALID_LEAD_STAGE', 'lead_id' => $lead->id], 422);
            }

            // Document status check (e.g. check if city/state are present)
            if (empty($lead->city) || empty($lead->state)) {
                return response()->json([
                    'message' => 'MISSING_REQUIRED_FIELDS',
                    'lead_id' => $lead->id,
                    'details' => 'City and State fields are required before advisor distribution.'
                ], 422);
            }

            $oldOwnerId = $lead->owner_id;

            $lead->assignment_type = 'Advisor Reassignment';
            $lead->assignment_reason = $request->input('reason') ?? 'Advisor Lead Distribution';

            $lead->update([
                'owner_id' => $advisor->id,
                'advisor_owner_id' => $advisor->id,
                'assignment_status' => 'assigned'
            ]);
        }

        return response()->json(['message' => 'ADVISOR_ASSIGN_SUCCESSFUL']);
    }

    public function bulkAssignSalesOwner(Request $request, SalesOwnerAssignmentService $service)
    {
        $request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['required', 'integer', 'exists:leads,id'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'assignment_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $request->user();
        $actor?->loadMissing('role');

        try {
            $service->assertActorCanAssign($actor);
            $leads = $service->assignMany(
                $request->input('lead_ids'),
                (int) $request->input('owner_id'),
                $actor,
                $request->input('assignment_notes') ?? $request->input('reason')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'SALES_OWNER_ASSIGN_SUCCESSFUL',
            'count' => $leads->count(),
            'leads' => $leads->values(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $lead = clone Lead::withTrashed()->findOrFail($id); // Handle both normal and trashed
        $user = $request->user();
        $roleKey = $user?->role?->key;
        
        if (!in_array($roleKey, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Unauthorized to delete leads'], 403);
        }
        
        if (!$lead->trashed()) {
            $lead->delete();
        }

        return response()->json(['message' => 'LEAD_DELETED']);
    }

    public function restore(Request $request, $id)
    {
        $lead = Lead::withTrashed()->findOrFail($id);
        $user = $request->user();
        $roleKey = $user?->role?->key;
        
        if (!in_array($roleKey, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Unauthorized to restore leads'], 403);
        }

        $lead->restore();

        return response()->json(['message' => 'LEAD_RESTORED']);
    }

    public function forceDelete(Request $request, $id)
    {
        $lead = Lead::withTrashed()->findOrFail($id);
        $user = $request->user();
        $roleKey = $user?->role?->key;
        
        // Only super_admin can force delete
        if ($roleKey !== 'super_admin') {
            return response()->json(['message' => 'Only Super Admin can permanently delete leads'], 403);
        }

        $lead->forceDelete();

        return response()->json(['message' => 'LEAD_PERMANENTLY_DELETED']);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'lead_ids' => ['required', 'array'],
            'lead_ids.*' => ['required', 'integer']
        ]);

        $user = $request->user();
        $roleKey = $user?->role?->key;
        
        if (!in_array($roleKey, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Unauthorized to bulk delete leads'], 403);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            Lead::whereIn('id', $request->input('lead_ids'))->delete();
        });

        return response()->json(['message' => 'LEADS_BULK_DELETED']);
    }

    public function bulkRestore(Request $request)
    {
        $request->validate([
            'lead_ids' => ['required', 'array'],
            'lead_ids.*' => ['required', 'integer']
        ]);

        $user = $request->user();
        $roleKey = $user?->role?->key;
        
        if (!in_array($roleKey, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'Unauthorized to bulk restore leads'], 403);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            Lead::withTrashed()->whereIn('id', $request->input('lead_ids'))->restore();
        });

        return response()->json(['message' => 'LEADS_BULK_RESTORED']);
    }
}
