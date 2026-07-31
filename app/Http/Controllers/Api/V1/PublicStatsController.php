<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;

class PublicStatsController extends Controller
{
    public function index(Request $request)
    {
        $activeLeads = Lead::whereHas('stage', function ($query) {
            $query->where('is_terminal', false);
        })->count();

        $teamMembers = User::where('status', 'active')->count();
        $conversionTarget = 15; // Target percentage

        return response()->json([
            'active_leads' => $activeLeads,
            'team_members' => $teamMembers,
            'conversion_target' => $conversionTarget,
        ]);
    }
}
