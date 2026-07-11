<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\AuditLog;
use App\Http\Requests\CreateCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Services\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CampaignController extends Controller
{
    private function checkPolicy(string $ability, ?Campaign $campaign = null)
    {
        // Resolve policy manually since they might not be registered globally
        $policy = new \App\Policies\CampaignPolicy();
        $actor = request()->user();
        if (!$actor) {
            abort(401);
        }

        $allowed = $campaign 
            ? $policy->{$ability}($actor, $campaign) 
            : $policy->{$ability}($actor);

        if (!$allowed) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $this->checkPolicy('viewAny');

        $query = Campaign::with(['owner', 'creator'])
            ->withCount('leads');

        // Text Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Status Filter
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        $sortField = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortField, $sortOrder);

        if ($request->boolean('all', false) || $request->input('paginate') === 'false') {
            return CampaignResource::collection($query->get());
        }

        $perPage = $request->integer('per_page', 20);
        return CampaignResource::collection($query->paginate($perPage));
    }

    public function store(CreateCampaignRequest $request, CampaignService $service)
    {
        $this->checkPolicy('create');

        $data = $request->validated();
        $actor = $request->user();

        $data['created_by'] = $actor->id;
        $data['owner_id'] = $data['owner_id'] ?? $actor->id;
        $data['department'] = $data['department'] ?? $actor->department;
        $data['spend'] = 0;
        $data['status'] = 'active';

        $campaign = Campaign::create($data);

        $service->logActivity($actor, 'created', $campaign, null, $data);

        return new CampaignResource($campaign->load(['owner', 'creator']));
    }

    public function show(Campaign $campaign)
    {
        $this->checkPolicy('view', $campaign);

        $campaign->loadMissing(['owner', 'creator'])->loadCount('leads');
        return new CampaignResource($campaign);
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign, CampaignService $service)
    {
        $this->checkPolicy('update', $campaign);

        $data = $request->validated();
        $actor = $request->user();

        $oldValues = $campaign->only(array_keys($data));
        $data['updated_by'] = $actor->id;

        $campaign->update($data);

        $service->logActivity($actor, 'edited', $campaign, $oldValues, $data);

        return new CampaignResource($campaign->load(['owner', 'creator']));
    }

    public function destroy(Request $request, Campaign $campaign, CampaignService $service)
    {
        $this->checkPolicy('delete', $campaign);

        $actor = $request->user();
        $service->logActivity($actor, 'deleted', $campaign, $campaign->toArray());

        $campaign->delete();

        return response()->json(['message' => 'CAMPAIGN_DELETED']);
    }

    public function overview(Campaign $campaign)
    {
        $this->checkPolicy('view', $campaign);

        $totalLeads = $campaign->leads()->count();

        // Lead stages counts
        $stages = LeadStage::all();
        $pipelineStats = [];

        foreach ($stages as $stage) {
            $count = $campaign->leads()->where('stage_id', $stage->id)->count();
            $pipelineStats[$stage->key] = $count;
        }

        // Calculate conversions
        $enrolledCount = $campaign->leads()->whereHas('stage', fn($q) => $q->where('type', 'won'))->count();
        $conversionRate = $totalLeads > 0 ? round(($enrolledCount / $totalLeads) * 100, 1) : 0;

        return response()->json([
            'campaign' => new CampaignResource($campaign->loadMissing(['owner', 'creator'])),
            'total_leads' => $totalLeads,
            'conversion_rate' => $conversionRate,
            'pipeline' => $pipelineStats
        ]);
    }

    public function leads(Request $request, Campaign $campaign)
    {
        $this->checkPolicy('view', $campaign);

        $query = $campaign->leads()->with(['owner', 'stage']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('student_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('stage_id')) {
            $query->where('stage_id', $request->input('stage_id'));
        }

        $perPage = $request->integer('per_page', 20);
        return response()->json($query->paginate($perPage));
    }

    public function performance(Campaign $campaign)
    {
        $this->checkPolicy('view', $campaign);

        // Daily leads counts
        $daily = $campaign->leads()
            ->selectRaw('DATE(created_at) as date, count(*) as count')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        // Stage distribution count
        $stages = LeadStage::all();
        $distribution = [];
        foreach ($stages as $stage) {
            $distribution[] = [
                'stage' => $stage->label,
                'count' => $campaign->leads()->where('stage_id', $stage->id)->count()
            ];
        }

        return response()->json([
            'daily_leads' => $daily,
            'stage_distribution' => $distribution
        ]);
    }

    public function timeline(Campaign $campaign)
    {
        $this->checkPolicy('view', $campaign);

        $logs = AuditLog::with('actor:id,first_name,last_name')
            ->where('entity_type', 'Campaign')
            ->where('entity_id', $campaign->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($logs);
    }

    public function updateStatus(Request $request, Campaign $campaign, CampaignService $service)
    {
        $this->checkPolicy('update', $campaign);

        $request->validate([
            'status' => ['required', 'string', 'in:draft,scheduled,active,paused,completed,archived']
        ]);

        $actor = $request->user();
        $service->transitionStatus($campaign, $request->input('status'), $actor);

        return new CampaignResource($campaign->load(['owner', 'creator']));
    }

    public function updateBudget(Request $request, Campaign $campaign, CampaignService $service)
    {
        $this->checkPolicy('update', $campaign);

        $request->validate([
            'budget' => ['required', 'numeric', 'min:0'],
            'spend' => ['required', 'numeric', 'min:0']
        ]);

        $actor = $request->user();
        $service->updateBudget($campaign, (float)$request->input('budget'), (float)$request->input('spend'), $actor);

        return new CampaignResource($campaign->load(['owner', 'creator']));
    }

    public function reports(Campaign $campaign)
    {
        $this->checkPolicy('view', $campaign);

        $leads = $campaign->leads()->with(['stage', 'owner'])->get();

        return response()->json([
            'campaign' => new CampaignResource($campaign->loadMissing(['owner', 'creator'])),
            'leads' => $leads
        ]);
    }
}
