<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index()
    {
        return response()->json(Task::query()->latest()->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'integer'],
            'assigned_to' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
        ]);

        return response()->json(Task::query()->create($data), 201);
    }

    public function update(Request $request, Task $task)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,in_progress,completed'],
            'completed_at' => ['nullable', 'date'],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
        ]);

        $task->update($data);
        return response()->json($task->fresh());
    }
}

