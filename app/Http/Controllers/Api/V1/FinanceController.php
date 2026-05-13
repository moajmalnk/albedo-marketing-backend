<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Payment;
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

        return response()->json([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'income' => $income,
            'expenses' => $expensesTotal,
            'net' => $income - $expensesTotal,
            'by_method' => $byMethod,
            'by_category' => $byCategory,
        ]);
    }
}
