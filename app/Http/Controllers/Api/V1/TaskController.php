<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed'])],
            'lead_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Task::query()
            ->with([
                'lead:id,student_name',
                'assignee:id,first_name,last_name',
            ])
            ->when($request->filled('owner_id'), fn ($q) => $q->where('assigned_to', (int) $request->input('owner_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('lead_id'), fn ($q) => $q->where('lead_id', (int) $request->input('lead_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('due_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('due_at', '<=', $request->date('to')))
            ->orderByRaw('CASE WHEN status = "completed" THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('id');

        $limit = (int) $request->input('limit', 50);
        $limit = max(1, min(100, $limit));

        return response()->json($query->paginate($limit));
    }

    public function show(Task $task)
    {
        return response()->json(
            $task->load([
                'lead:id,student_name',
                'assignee:id,first_name,last_name',
            ])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed'])],
        ]);

        $task = Task::query()->create($data);

        return response()->json(
            $task->load([
                'lead:id,student_name',
                'assignee:id,first_name,last_name',
            ]),
            201
        );
    }

    public function update(Request $request, Task $task)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed'])],
            'completed_at' => ['nullable', 'date'],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $task->update($data);

        return response()->json(
            $task->fresh()->load([
                'lead:id,student_name',
                'assignee:id,first_name,last_name',
            ])
        );
    }

    public function destroy(Task $task)
    {
        $task->delete();

        return response()->json(['message' => 'Task deleted']);
    }
}
