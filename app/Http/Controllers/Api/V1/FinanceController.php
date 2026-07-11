<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function summary(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : now()->subDays(30)->startOfDay();
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        $income = (float) Payment::query()
            ->whereBetween('received_at', [$from, $to])
            ->sum('amount');

        $expensesTotal = (float) Expense::query()
            ->whereBetween('spent_at', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');
            
        $enrollmentsQuery = \App\Models\Enrollment::query()
            ->whereBetween('created_at', [$from, $to]);

        $admissionsRevenue = (float) $enrollmentsQuery->sum('package_amount');
        $outstandingAmount = (float) $enrollmentsQuery->sum('balance_amount');
        $pendingPaymentsCount = (int) (clone $enrollmentsQuery)->where('balance_amount', '>', 0)->count();
        
        $collectedPaymentsCount = (int) Payment::query()
            ->whereBetween('received_at', [$from, $to])
            ->count();

        $byMethod = Payment::query()
            ->select('method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->whereBetween('received_at', [$from, $to])
            ->groupBy('method')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ]);

        $byCategory = Expense::query()
            ->select('category_id', 'department', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->whereBetween('spent_at', [$from->toDateString(), $to->toDateString()])
            ->groupBy('category_id', 'department')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id,
                'department' => $row->department,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ]);

        $trendStart = now()->subMonths(5)->startOfMonth();
        $trendEnd = now()->endOfMonth();

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        $incomeQuery = Payment::query()
            ->selectRaw($isSqlite 
                ? 'strftime("%Y", received_at) as year, strftime("%m", received_at) as month, SUM(amount) as total'
                : 'YEAR(received_at) as year, MONTH(received_at) as month, SUM(amount) as total')
            ->whereBetween('received_at', [$trendStart, $trendEnd])
            ->groupByRaw($isSqlite ? 'strftime("%Y", received_at), strftime("%m", received_at)' : 'YEAR(received_at), MONTH(received_at)')
            ->get()->keyBy(fn ($item) => $item->year . '-' . str_pad($item->month, 2, '0', STR_PAD_LEFT));

        $expenseQuery = Expense::query()
            ->selectRaw($isSqlite
                ? 'strftime("%Y", spent_at) as year, strftime("%m", spent_at) as month, SUM(amount) as total'
                : 'YEAR(spent_at) as year, MONTH(spent_at) as month, SUM(amount) as total')
            ->whereBetween('spent_at', [$trendStart->toDateString(), $trendEnd->toDateString()])
            ->groupByRaw($isSqlite ? 'strftime("%Y", spent_at), strftime("%m", spent_at)' : 'YEAR(spent_at), MONTH(spent_at)')
            ->get()->keyBy(fn ($item) => $item->year . '-' . str_pad($item->month, 2, '0', STR_PAD_LEFT));

        $leadsQuery = Lead::query()
            ->leftJoin('lead_stages', 'leads.stage_id', '=', 'lead_stages.id')
            ->selectRaw($isSqlite
                ? 'strftime("%Y", leads.created_at) as year, strftime("%m", leads.created_at) as month, COUNT(leads.id) as count, SUM(CASE WHEN lead_stages.key = \'enrolled\' THEN 1 ELSE 0 END) as admissions_count'
                : 'YEAR(leads.created_at) as year, MONTH(leads.created_at) as month, COUNT(leads.id) as count, SUM(CASE WHEN lead_stages.key = \'enrolled\' THEN 1 ELSE 0 END) as admissions_count')
            ->whereBetween('leads.created_at', [$trendStart, $trendEnd])
            ->groupByRaw($isSqlite ? 'strftime("%Y", leads.created_at), strftime("%m", leads.created_at)' : 'YEAR(leads.created_at), MONTH(leads.created_at)')
            ->get()->keyBy(fn ($item) => $item->year . '-' . str_pad($item->month, 2, '0', STR_PAD_LEFT));

        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthObj = now()->subMonths($i);
            $key = $monthObj->format('Y-m');
            
            $monthlyTrend[] = [
                'month' => $monthObj->format('M'),
                'spend' => (float) ($expenseQuery[$key]->total ?? 0),
                'revenue' => (float) ($incomeQuery[$key]->total ?? 0),
                'leads' => (int) ($leadsQuery[$key]->count ?? 0),
                'admissions' => (int) ($leadsQuery[$key]->admissions_count ?? 0),
            ];
        }

        $recentTransactions = Payment::query()
            ->with(['enrollment.lead:id,student_name', 'receiver:id,first_name,last_name'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'income' => $income,
            'expenses' => $expensesTotal,
            'net' => $income - $expensesTotal,
            'admissions_revenue' => $admissionsRevenue,
            'outstanding_amount' => $outstandingAmount,
            'pending_payments' => $pendingPaymentsCount,
            'collected_payments' => $collectedPaymentsCount,
            'by_method' => $byMethod,
            'by_category' => $byCategory,
            'monthly_trend' => $monthlyTrend,
            'recent_transactions' => $recentTransactions,
        ]);
    }
}
