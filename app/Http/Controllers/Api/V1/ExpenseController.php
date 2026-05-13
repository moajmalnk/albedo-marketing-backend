<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer'],
            'department' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $query = Expense::query()
            ->with(['creator:id,first_name,last_name'])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', (int) $request->input('category_id')))
            ->when($request->filled('department'), fn ($q) => $q->where('department', $request->string('department')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('spent_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('spent_at', '<=', $request->date('to')))
            ->orderByDesc('spent_at')
            ->orderByDesc('id');

        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min(500, $limit));

        return response()->json($query->paginate($limit));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0'],
            'spent_at' => ['required', 'date'],
            'department' => ['nullable', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()?->id;

        $expense = Expense::query()->create($data);

        return response()->json($expense->load(['creator:id,first_name,last_name']), 201);
    }

    public function show(Expense $expense)
    {
        return response()->json($expense->load(['creator:id,first_name,last_name']));
    }

    public function update(Request $request, Expense $expense)
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'spent_at' => ['sometimes', 'required', 'date'],
            'department' => ['nullable', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ]);

        $expense->update($data);

        return response()->json($expense->fresh()->load(['creator:id,first_name,last_name']));
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();

        return response()->json(['message' => 'Expense deleted']);
    }
}
