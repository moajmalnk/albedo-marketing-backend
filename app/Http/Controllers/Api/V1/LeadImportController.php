<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\ValidationException;

class LeadImportController extends Controller
{
    protected $importService;

    public function __construct(ImportService $importService)
    {
        $this->importService = $importService;
    }

    /**
     * Enforce permissions on the endpoint.
     */
    private function checkAccess(Request $request, string $action = 'view'): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $role = $user->role?->key;

        if ($action === 'delete') {
            if (!in_array($role, ['super_admin', 'admin'], true)) {
                abort(403, 'Only administrators can delete import logs.');
            }
            return;
        }

        // View/Import access
        if (!in_array($role, ['super_admin', 'admin', 'dept_head', 'department_head', 'marketer'], true)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * POST /api/v1/imports
     */
    public function store(Request $request)
    {
        $this->checkAccess($request, 'import');

        $validated = $request->validate([
            'rows' => ['required', 'array', 'max:5000'],
            'file_name' => ['required', 'string', 'max:255'],
            'source' => ['required', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:255'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'department' => ['nullable', 'string', 'max:255'],
            'duplicate_strategy' => ['required', 'string', 'in:skip,update,merge,force'],
            'duplicate_criteria' => ['required', 'string', 'in:phone,email,both'],
            'mapping_profile' => ['required', 'array'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $dryRun = $request->boolean('dry_run', false);

        if ($dryRun) {
            $report = $this->importService->dryRun(
                $validated['rows'],
                $validated['mapping_profile'],
                $validated['duplicate_criteria']
            );
            return response()->json($report);
        }

        $import = $this->importService->startImport(
            $request->user(),
            $validated['rows'],
            $validated
        );

        return response()->json([
            'message' => $import->status === 'Queued' ? 'Import queued in background.' : 'Import completed.',
            'import' => $import
        ]);
    }

    /**
     * GET /api/v1/imports
     */
    public function index(Request $request)
    {
        $this->checkAccess($request, 'view');

        $user = $request->user();
        $query = LeadImport::with('user:id,first_name,last_name,email');

        // Filter own imports for marketers / non-admins
        $role = $user->role?->key;
        if (!in_array($role, ['super_admin', 'admin', 'dept_head', 'department_head'], true)) {
            $query->where('user_id', $user->id);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('file_name', 'like', "%{$search}%")
                  ->orWhere('source', 'like', "%{$search}%")
                  ->orWhere('campaign', 'like', "%{$search}%");
            });
        }

        // Filter by Status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        // Filter by Source
        if ($request->filled('source') && $request->input('source') !== 'all') {
            $query->where('source', $request->input('source'));
        }

        // Filter by Date range
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->input('start_date'), $request->input('end_date')]);
        }

        $imports = $query->orderBy('created_at', 'desc')->paginate($request->integer('per_page', 20));

        // Get overall stats/metrics (uncached / live)
        $statsQuery = LeadImport::query();
        if (!in_array($role, ['super_admin', 'admin', 'dept_head', 'department_head'], true)) {
            $statsQuery->where('user_id', $user->id);
        }

        $today = now()->startOfDay();
        $startOfWeek = now()->startOfWeek();
        $startOfMonth = now()->startOfMonth();

        $allStats = (clone $statsQuery)->selectRaw("
            COUNT(*) as total_imports,
            SUM(total_rows) as total_records,
            SUM(duplicate_count) as duplicate_records,
            SUM(rejected_count) as rejected_records,
            SUM(failed_count) as failed_records,
            SUM(accepted_count) as accepted_records,
            SUM(CASE WHEN created_at >= '{$today}' THEN 1 ELSE 0 END) as today_imports,
            SUM(CASE WHEN created_at >= '{$startOfWeek}' THEN 1 ELSE 0 END) as week_imports,
            SUM(CASE WHEN created_at >= '{$startOfMonth}' THEN 1 ELSE 0 END) as month_imports,
            AVG(duration_seconds) as avg_duration
        ")->first();

        $successRate = ($allStats->total_records ?? 0) > 0
            ? round((($allStats->accepted_records ?? 0) / $allStats->total_records) * 100, 1) . '%'
            : '100%';

        $duplicateRate = ($allStats->total_records ?? 0) > 0
            ? round((($allStats->duplicate_records ?? 0) / $allStats->total_records) * 100, 1) . '%'
            : '0%';

        $rejectedRate = ($allStats->total_records ?? 0) > 0
            ? round(((($allStats->rejected_records ?? 0) + ($allStats->failed_records ?? 0)) / $allStats->total_records) * 100, 1) . '%'
            : '0%';

        return response()->json([
            'metrics' => [
                'totalImports' => (int)($allStats->total_imports ?? 0),
                'totalRecords' => (int)($allStats->total_records ?? 0),
                'duplicateRecords' => (int)($allStats->duplicate_records ?? 0),
                'rejectedRecords' => (int)($allStats->rejected_records ?? 0) + (int)($allStats->failed_records ?? 0),
                'successRate' => $successRate,
                'duplicateRate' => $duplicateRate,
                'rejectedRate' => $rejectedRate,
                'todayImports' => (int)($allStats->today_imports ?? 0),
                'weekImports' => (int)($allStats->week_imports ?? 0),
                'monthImports' => (int)($allStats->month_imports ?? 0),
                'avgImportTime' => round($allStats->avg_duration ?? 0, 1) . 's',
            ],
            'imports' => $imports
        ]);
    }

    /**
     * GET /api/v1/imports/{id}
     */
    public function show(Request $request, $id)
    {
        $this->checkAccess($request, 'view');

        $user = $request->user();
        $import = LeadImport::with(['user:id,first_name,last_name,email', 'rows'])->findOrFail($id);

        // Enforce own imports unless admin
        $role = $user->role?->key;
        if (!in_array($role, ['super_admin', 'admin', 'dept_head', 'department_head'], true) && $import->user_id !== $user->id) {
            abort(403);
        }

        return response()->json($import);
    }

    /**
     * DELETE /api/v1/imports/{id}
     */
    public function destroy(Request $request, $id)
    {
        $this->checkAccess($request, 'delete');

        $import = LeadImport::findOrFail($id);
        $import->delete();

        return response()->json(['message' => 'Import log deleted successfully.']);
    }

    /**
     * GET /api/v1/imports/{id}/report
     */
    public function report(Request $request, $id)
    {
        $this->checkAccess($request, 'view');

        $import = LeadImport::findOrFail($id);
        $rows = LeadImportRow::where('import_id', $import->id)->get();

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=import_error_report_{$id}.csv",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];

        $callback = function () use ($rows) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Row Number', 'Status', 'Error Message', 'Payload Data']);

            foreach ($rows as $row) {
                fputcsv($file, [
                    $row->row_number,
                    $row->status,
                    $row->error_message,
                    json_encode($row->payload)
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * GET /api/v1/imports/templates
     */
    public function templates(Request $request)
    {
        $this->checkAccess($request, 'view');

        return response()->json([
            [
                'key' => 'generic',
                'name' => 'Generic Leads Template',
                'description' => 'Use for standard list uploads. Includes all standard columns.',
                'url' => '/api/v1/imports/templates/generic'
            ],
            [
                'key' => 'meta',
                'name' => 'Meta Ads Leads Template',
                'description' => 'Optimized mapping for leads captured via Facebook or Instagram Ads.',
                'url' => '/api/v1/imports/templates/meta'
            ],
            [
                'key' => 'google',
                'name' => 'Google Ads Leads Template',
                'description' => 'Optimized mapping for Google Search and Performance Max campaigns.',
                'url' => '/api/v1/imports/templates/google'
            ]
        ]);
    }

    /**
     * GET /api/v1/imports/templates/{type}
     */
    public function downloadTemplate(Request $request, $type)
    {
        $this->checkAccess($request, 'view');

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=albedo_{$type}_lead_template.csv",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];

        $columns = [];
        $example = [];

        if ($type === 'meta') {
            $columns = ['student_name', 'phone', 'email', 'city', 'campaign', 'source'];
            $example = ['Jane Doe', '919876543210', 'jane@example.com', 'Kochi', 'Meta leads campaign', 'Meta'];
        } elseif ($type === 'google') {
            $columns = ['student_name', 'phone', 'email', 'city', 'campaign', 'source'];
            $example = ['John Smith', '919876543211', 'john@example.com', 'Calicut', 'Google search campaign', 'Google'];
        } else {
            // Generic template
            $columns = ['student_name', 'phone', 'email', 'class', 'syllabus', 'city', 'district', 'state', 'country', 'source_code', 'campaign', 'source_group'];
            $example = ['Sarah Connor', '919876543212', 'sarah@example.com', '12', 'CBSE', 'Trivandrum', 'Trivandrum', 'Kerala', 'India', 'import', 'Organic campaign', 'other'];
        }

        $callback = function () use ($columns, $example, $type) {
            $file = fopen('php://output', 'w');
            
            // Add instruction rules at the top
            fputcsv($file, ['# INSTRUCTIONS:', 'Please do not change the column headers.', '', '', '', '']);
            fputcsv($file, ['# REQUIRED FIELDS:', 'student_name, phone. Phone number must be unique.', '', '', '', '']);
            fputcsv($file, ['# SUPPORTED VALUES:', 'syllabus: STATE, CBSE, ICSE, IGCSE, IB. source_group: influence, performance, albedo, reference, other.', '', '', '', '']);
            fputcsv($file, []); // Blank separator row
            
            // Write columns and example row
            fputcsv($file, $columns);
            fputcsv($file, $example);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
