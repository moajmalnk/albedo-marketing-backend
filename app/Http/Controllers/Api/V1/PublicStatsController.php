<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;

class PublicStatsController extends Controller
{
    /**
     * Return public statistics for the landing page.
     * Only returns aggregated counts to prevent exposing sensitive data.
     */
    public function index(Request $request)
    {
        return response()->json([
            'active_leads' => Lead::count(),
            'team_members' => User::count(),
            'conversion_target' => 30, // Default static target as per landing page requirements
        ]);
    }
}
