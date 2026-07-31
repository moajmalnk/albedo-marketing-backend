<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\ChallengeCategoryController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\CallLogController;
use App\Http\Controllers\Api\V1\LeadActivityController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadFormOptionController;
use App\Http\Controllers\Api\V1\LeadHistoryController;
use App\Http\Controllers\Api\V1\LeadImportController;
use App\Http\Controllers\Api\V1\LeadAssignmentController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\MarketingChallengeController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductTargetController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TeamTipController;
use App\Http\Controllers\Api\V1\TelephonyWebhookController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserTargetController;
use App\Http\Controllers\Api\V1\WhatsAppSessionController;
use App\Http\Controllers\Api\V1\WfhRequestController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Temporary probe route to confirm Hostinger receives /api traffic correctly.
Route::any('/sanctum/csrf-cookie-probe', function () {
    return response()->json([
        'ok' => true,
        'path' => request()->path(),
        'origin' => request()->header('Origin'),
    ]);
});

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/webhooks/foxbay/call', [TelephonyWebhookController::class, 'store']);
    Route::post('/telephony/webhook', [TelephonyWebhookController::class, 'store']);
    Route::get('/public-stats', [\App\Http\Controllers\Api\V1\PublicStatsController::class, 'index']);

    Route::middleware('whatsapp.worker')->group(function (): void {
        Route::get('/whatsapp/worker/sessions', [WhatsAppWebhookController::class, 'workerSessions']);
        Route::patch('/whatsapp/worker/sessions/{whatsapp_session}', [WhatsAppWebhookController::class, 'workerUpdateSession']);
        Route::post('/whatsapp/capture', [WhatsAppWebhookController::class, 'captureLead']);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/me', [UserController::class, 'me']);
        Route::patch('/me', [UserController::class, 'updateMe']);
        Route::get('/user-targets/my-progress', [UserTargetController::class, 'myProgress']);

        Route::apiResource('lead-sources', \App\Http\Controllers\LeadSourceController::class)->except(['show']);
        
        Route::apiResource('lead-scoring-rules', \App\Http\Controllers\Api\V1\LeadScoringRuleController::class)->except(['show']);
        Route::apiResource('lead-score-tiers', \App\Http\Controllers\Api\V1\LeadScoreTierController::class)->only(['index', 'update']);
        Route::get('duplicate-rule', [\App\Http\Controllers\Api\V1\DuplicateRuleController::class, 'show']);
        Route::post('duplicate-rule', [\App\Http\Controllers\Api\V1\DuplicateRuleController::class, 'update']);
        Route::get('duplicate-rule/logs', [\App\Http\Controllers\Api\V1\DuplicateRuleController::class, 'logs']);

        // Lead Stages & Closed Reasons (Config Engine)
        Route::get('lead-stages', [\App\Http\Controllers\Api\V1\LeadStageController::class, 'index']);
        Route::post('lead-stages', [\App\Http\Controllers\Api\V1\LeadStageController::class, 'store']);
        Route::patch('lead-stages/{leadStage}', [\App\Http\Controllers\Api\V1\LeadStageController::class, 'update']);
        Route::post('lead-stages/reorder', [\App\Http\Controllers\Api\V1\LeadStageController::class, 'reorder']);
        Route::delete('lead-stages/{leadStage}', [\App\Http\Controllers\Api\V1\LeadStageController::class, 'destroy']);

        Route::get('lead-closed-reasons', [\App\Http\Controllers\Api\V1\LeadClosedReasonController::class, 'index']);
        Route::post('lead-closed-reasons', [\App\Http\Controllers\Api\V1\LeadClosedReasonController::class, 'store']);
        Route::patch('lead-closed-reasons/{leadClosedReason}', [\App\Http\Controllers\Api\V1\LeadClosedReasonController::class, 'update']);
        Route::delete('lead-closed-reasons/{leadClosedReason}', [\App\Http\Controllers\Api\V1\LeadClosedReasonController::class, 'destroy']);

        Route::get('lead-filter-sets', [\App\Http\Controllers\Api\V1\LeadFilterSetController::class, 'index']);
        Route::middleware('role:super_admin,admin')->group(function (): void {
            Route::post('lead-filter-sets', [\App\Http\Controllers\Api\V1\LeadFilterSetController::class, 'store']);
            Route::patch('lead-filter-sets/{leadFilterSet}', [\App\Http\Controllers\Api\V1\LeadFilterSetController::class, 'update']);
            Route::delete('lead-filter-sets/{leadFilterSet}', [\App\Http\Controllers\Api\V1\LeadFilterSetController::class, 'destroy']);
        });

        // Lead Recycling
        Route::get('leads/recycle', [\App\Http\Controllers\Api\V1\LeadRecycleController::class, 'index']);
        Route::post('leads/recycle/auto', [\App\Http\Controllers\Api\V1\LeadRecycleController::class, 'autoRecycle']);
        Route::post('leads/{lead}/recycle', [\App\Http\Controllers\Api\V1\LeadRecycleController::class, 'recycleSingle']);
        Route::post('leads/{lead}/archive-recycle', [\App\Http\Controllers\Api\V1\LeadRecycleController::class, 'archive']);

        Route::middleware('role:super_admin,admin')->group(function (): void {
            Route::get('/settings', [\App\Http\Controllers\Api\V1\SystemSettingController::class, 'index']);
            Route::post('/settings', [\App\Http\Controllers\Api\V1\SystemSettingController::class, 'update']);

            Route::get('/settings/attendance', [\App\Http\Controllers\Api\V1\AttendanceSettingController::class, 'show']);
            Route::put('/settings/attendance', [\App\Http\Controllers\Api\V1\AttendanceSettingController::class, 'update']);

            Route::apiResource('leave-types', \App\Http\Controllers\Api\V1\LeaveTypeController::class)->except(['show']);
            Route::apiResource('holidays', \App\Http\Controllers\Api\V1\HolidayController::class)->except(['show']);

            Route::get('/roles', [RoleController::class, 'index']);

            Route::post('/departments/bulk/activate', [DepartmentController::class, 'bulkActivate']);
            Route::post('/departments/bulk/deactivate', [DepartmentController::class, 'bulkDeactivate']);
            Route::post('/departments/bulk/restore', [DepartmentController::class, 'bulkRestore']);
            Route::post('/departments/bulk/delete', [DepartmentController::class, 'bulkDestroy']);
            Route::patch('/departments/{id}/restore', [DepartmentController::class, 'restore']);
            Route::get('/departments/{id}/members', [DepartmentController::class, 'members']);
            Route::apiResource('departments', DepartmentController::class);
            // Write ops admin-only; index is available to all authenticated users below
            Route::apiResource('sub-brands', \App\Http\Controllers\Api\V1\SubBrandController::class)
                ->except(['index']);
            Route::apiResource('attendance', AttendanceController::class)->except(['show']);
        });

        // Readable by any authenticated role (dept heads, sales, imports, dashboards)
        Route::get('/sub-brands', [\App\Http\Controllers\Api\V1\SubBrandController::class, 'index']);

        Route::get('/challenge-categories', [ChallengeCategoryController::class, 'index']);
        Route::post('/challenge-categories', [ChallengeCategoryController::class, 'store']);
        Route::patch('/challenge-categories/{challenge_category}', [ChallengeCategoryController::class, 'update']);
        Route::delete('/challenge-categories/{challenge_category}', [ChallengeCategoryController::class, 'destroy']);

        Route::apiResource('marketing-challenges', MarketingChallengeController::class);
        Route::post('marketing-challenges/{marketing_challenge}/comments', [MarketingChallengeController::class, 'addComment']);

        Route::get('/campaigns/{campaign}/overview', [\App\Http\Controllers\Api\V1\CampaignController::class, 'overview']);
        Route::get('/campaigns/{campaign}/leads', [\App\Http\Controllers\Api\V1\CampaignController::class, 'leads']);
        Route::get('/campaigns/{campaign}/performance', [\App\Http\Controllers\Api\V1\CampaignController::class, 'performance']);
        Route::get('/campaigns/{campaign}/timeline', [\App\Http\Controllers\Api\V1\CampaignController::class, 'timeline']);
        Route::patch('/campaigns/{campaign}/status', [\App\Http\Controllers\Api\V1\CampaignController::class, 'updateStatus']);
        Route::patch('/campaigns/{campaign}/budget', [\App\Http\Controllers\Api\V1\CampaignController::class, 'updateBudget']);
        Route::get('/campaigns/{campaign}/reports', [\App\Http\Controllers\Api\V1\CampaignController::class, 'reports']);
        Route::apiResource('campaigns', \App\Http\Controllers\Api\V1\CampaignController::class);

        Route::get('/team-tips/categories', [TeamTipController::class, 'categories']);
        Route::post('/team-tips/categories', [TeamTipController::class, 'storeCategory']);
        Route::get('/team-tips/stats', [TeamTipController::class, 'stats']);
        Route::get('/team-tips/my', [TeamTipController::class, 'mine']);
        Route::post('/team-tips/read-normal', [TeamTipController::class, 'markNormalRead']);
        Route::post('/team-tips/{team_tip}/read', [TeamTipController::class, 'markRead']);
        Route::post('/team-tips/{team_tip}/bookmark', [TeamTipController::class, 'bookmark']);
        Route::delete('/team-tips/{team_tip}/bookmark', [TeamTipController::class, 'unbookmark']);
        Route::apiResource('team-tips', TeamTipController::class);

        Route::get('/users/for-lead-form', [UserController::class, 'forLeadForm']);
        Route::get('/users/export/csv', [UserController::class, 'exportCsv']);
        Route::post('/users/bulk/activate', [UserController::class, 'bulkActivate']);
        Route::post('/users/bulk/deactivate', [UserController::class, 'bulkDeactivate']);
        Route::post('/users/bulk/delete', [UserController::class, 'bulkDelete']);
        Route::post('/users/bulk/restore', [UserController::class, 'bulkRestore']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::patch('/users/{user}/password', [UserController::class, 'resetPassword']);
        Route::patch('/users/{id}/restore', [UserController::class, 'restore']);
        Route::post('/users/{user}/impersonate', [UserController::class, 'impersonate']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::delete('/users/{id}/force', [UserController::class, 'forceDelete']);
        Route::get('/users/{user}/stats', [UserController::class, 'stats']);
        Route::get('/users/{user}/activities', [UserController::class, 'activities']);

        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendance/break/start', [AttendanceController::class, 'startBreak']);
        Route::post('/attendance/break/end', [AttendanceController::class, 'endBreak']);
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('/attendance/today', [AttendanceController::class, 'today']);
        Route::get('/attendance/pending-corrections', [AttendanceController::class, 'pendingCorrections']);
        Route::post('/attendance/correct-log', [AttendanceController::class, 'correctLog']);
        Route::get('/attendance/reports', [AttendanceController::class, 'reports']);
        Route::get('/attendance/monthly-summary', [AttendanceController::class, 'monthlySummary']);
        Route::get('/attendance/summaries', [AttendanceController::class, 'summaries']);

        Route::middleware('leadership.override')->group(function (): void {
            Route::middleware('active.checkin')->group(function (): void {
                Route::get('/leads/kpis', [LeadController::class, 'kpis']);
                Route::get('/leads', [LeadController::class, 'index']);
                Route::get('/leads/{lead}', [LeadController::class, 'show']);
                Route::get('/lead-form-options', [LeadFormOptionController::class, 'index']);
                Route::post('/lead-form-options', [LeadFormOptionController::class, 'store']);
                Route::patch('/lead-form-options/{lead_form_option}', [LeadFormOptionController::class, 'update']);
                Route::delete('/lead-form-options/{lead_form_option}', [LeadFormOptionController::class, 'destroy']);

                Route::apiResource('product-targets', ProductTargetController::class);
                Route::apiResource('user-targets', UserTargetController::class);

                Route::post('/leads', [LeadController::class, 'store']);
                Route::patch('/leads/{lead}', [LeadController::class, 'update']);
                Route::post('/leads/{lead}/assign', [LeadAssignmentController::class, 'assign']);
                Route::post('/leads/{lead}/reassign', [LeadAssignmentController::class, 'reassign']);
                Route::get('/lead-assignments', [LeadAssignmentController::class, 'index']);
                Route::get('/lead-assignments/{lead}', [LeadAssignmentController::class, 'show']);
                Route::patch('/leads/{lead}/stage', [LeadController::class, 'changeStage']);
                Route::post('/leads/{lead}/handoff-to-sales', [LeadController::class, 'handoffToSales']);
                Route::get('/imports/templates', [LeadImportController::class, 'templates']);
                Route::get('/imports/templates/{type}', [LeadImportController::class, 'downloadTemplate']);
                Route::get('/imports/{import}/report', [LeadImportController::class, 'report']);
                Route::apiResource('imports', LeadImportController::class)->except(['update']);
                Route::post('/leads/import', [LeadImportController::class, 'store']);
                Route::get('/lead-imports', [LeadImportController::class, 'index']);
                // Production Lead Intake & Assignment Routes
                Route::get('/leads/assignment-queue', [LeadController::class, 'assignmentQueue']);
                Route::get('/leads/assignment-stats', [LeadController::class, 'assignmentStats']);
                Route::post('/leads/bulk-assign', [LeadController::class, 'bulkAssign']);
                Route::get('/leads/assignment-history', [LeadController::class, 'assignmentHistory']);
                Route::get('/telecallers/capacities', [UserController::class, 'telecallerCapacities']);
                
                // Lead Delete & Restore Routes
                Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);
                Route::patch('/leads/{lead}/restore', [LeadController::class, 'restore']);
                Route::delete('/leads/{lead}/force', [LeadController::class, 'forceDelete']);
                Route::post('/leads/bulk-delete', [LeadController::class, 'bulkDelete']);
                Route::post('/leads/bulk-restore', [LeadController::class, 'bulkRestore']);

                // Lead Documents Routes
                Route::get('/leads/{lead}/documents', [\App\Http\Controllers\Api\V1\LeadDocumentController::class, 'index']);
                Route::post('/leads/{lead}/documents', [\App\Http\Controllers\Api\V1\LeadDocumentController::class, 'store']);
                Route::delete('/leads/{lead}/documents/{document}', [\App\Http\Controllers\Api\V1\LeadDocumentController::class, 'destroy']);
                Route::get('/leads/{lead}/documents/{document}/download', [\App\Http\Controllers\Api\V1\LeadDocumentController::class, 'download']);

                // Imported Leads and Marketing Recycle Bin Routes
                Route::get('/imported-leads/assigned-history', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'assignedHistory']);
                Route::get('/imported-leads', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'index']);
                Route::post('/imported-leads', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'store']);
                Route::put('/imported-leads/{id}', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'update']);
                Route::delete('/imported-leads/{id}', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'destroy']);
                Route::post('/imported-leads/restore', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'restore']);
                Route::post('/imported-leads/bulk-delete', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'bulkDelete']);
                Route::delete('/imported-leads/{id}/force', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'forceDelete']);

                Route::get('/recycle-bin', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'recycleBinIndex']);
                Route::post('/recycle-bin/restore', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'restore']);
                Route::delete('/recycle-bin/{id}', [\App\Http\Controllers\Api\V1\ImportedLeadController::class, 'forceDelete']);
                
                // Production Lead Distribution Routes
                Route::get('/leads/distribution-stats', [LeadController::class, 'distributionStats']);
                Route::get('/leads/psa-queue', [LeadController::class, 'psaQueue']);
                Route::get('/leads/advisor-queue', [LeadController::class, 'advisorQueue']);
                Route::post('/leads/bulk-assign-psa', [LeadController::class, 'bulkAssignPsa']);
                Route::post('/leads/bulk-assign-advisor', [LeadController::class, 'bulkAssignAdvisor']);
                Route::post('/leads/bulk-assign-sales-owner', [LeadController::class, 'bulkAssignSalesOwner']);
                Route::get('/sales-staff/psas', [UserController::class, 'availablePsas']);
                Route::get('/sales-staff/advisors', [UserController::class, 'availableAdvisors']);

                Route::get('/leads/{lead}/activities', [LeadActivityController::class, 'index']);
                Route::post('/leads/{lead}/activities', [LeadActivityController::class, 'store']);
                Route::get('/leads/{lead}/history', [LeadHistoryController::class, 'index']);

                Route::get('/call-logs', [CallLogController::class, 'index']);
                Route::post('/call-logs/unknown/{unknownCall}/link', [CallLogController::class, 'link']);
                Route::post('/call-logs/unknown/{unknownCall}/ignore', [CallLogController::class, 'ignore']);

                Route::get('/calendar/events', [CalendarController::class, 'events']);
            });

            // Tasks can be viewed and managed even after checkout or before checkin
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::post('/tasks/bulk-from-leads', [TaskController::class, 'bulkFromLeads']);
            Route::get('/tasks/{task}', [TaskController::class, 'show']);
            Route::patch('/tasks/{task}', [TaskController::class, 'update']);
            Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
        });

        Route::get('/analytics/productivity', [AnalyticsController::class, 'productivity']);
        Route::get('/analytics/marketing', [AnalyticsController::class, 'marketing']);
        Route::get('/analytics/lead-quality', [AnalyticsController::class, 'leadQuality']);
        Route::get('/analytics/role-summary', [AnalyticsController::class, 'roleSummary']);
        Route::get('/analytics/team-insights', [AnalyticsController::class, 'teamInsights']);

        Route::middleware('role:super_admin,admin,sales_head,psa,advisor')->group(function (): void {
            Route::apiResource('enrollments', EnrollmentController::class);
            Route::get('/enrollments/{enrollment}/payments', [PaymentController::class, 'index']);
            Route::post('/enrollments/{enrollment}/payments', [PaymentController::class, 'store']);
            Route::get('/payments', [PaymentController::class, 'index']);
            Route::patch('/payments/{payment}', [PaymentController::class, 'update']);
            Route::delete('/payments/{payment}', [PaymentController::class, 'destroy']);
        });

        Route::middleware('role:super_admin,admin')->group(function (): void {
            Route::apiResource('expenses', ExpenseController::class);
        });

        Route::middleware('role:super_admin')->group(function (): void {
            Route::get('/finance/summary', [FinanceController::class, 'summary']);
            Route::get('/audit-logs', [\App\Http\Controllers\Api\V1\AuditLogController::class, 'index']);
        });

        Route::get('/whatsapp/sessions', [WhatsAppSessionController::class, 'index']);
        Route::post('/whatsapp/sessions', [WhatsAppSessionController::class, 'store']);
        Route::delete('/whatsapp/sessions/{whatsappSession}', [WhatsAppSessionController::class, 'destroy']);
        Route::get('/whatsapp/sessions/user/{user}/qr', [WhatsAppSessionController::class, 'qr']);

        Route::get('/leaves', [LeaveController::class, 'index']);
        Route::post('/leaves', [LeaveController::class, 'store']);
        Route::patch('/leaves/{leave}', [LeaveController::class, 'update']);
        Route::delete('/leaves/{leave}', [LeaveController::class, 'destroy']);

        Route::get('/wfh-requests', [WfhRequestController::class, 'index']);
        Route::post('/wfh-requests', [WfhRequestController::class, 'store']);
        Route::patch('/wfh-requests/{wfh_request}', [WfhRequestController::class, 'update']);
        Route::delete('/wfh-requests/{wfh_request}', [WfhRequestController::class, 'destroy']);
        
    });
});
