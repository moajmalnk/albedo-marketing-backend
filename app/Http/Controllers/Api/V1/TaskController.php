<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    private const SALES_TASK_ACTOR_ROLES = ['sales_head', 'admin', 'super_admin'];

    private function taskRelations(): array
    {
        return [
            'lead:id,student_name',
            'assignee:id,first_name,last_name',
            'creator:id,first_name,last_name',
        ];
    }

    private function actorRoleKey(Request $request): string
    {
        return $request->user()?->role?->key ?? '';
    }

    private function assertCanManageSalesTasks(Request $request): void
    {
        if (! in_array($this->actorRoleKey($request), self::SALES_TASK_ACTOR_ROLES, true)) {
            abort(403, 'Only sales heads and admins can create callback tasks for advisor/PSA owners.');
        }
    }

    public function index(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed'])],
            'lead_id' => ['nullable', 'integer'],
            'assignee_role' => ['nullable', Rule::in(['advisor', 'psa'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Task::query()
            ->with($this->taskRelations())
            ->when($request->filled('owner_id'), fn ($q) => $q->where('assigned_to', (int) $request->input('owner_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('lead_id'), fn ($q) => $q->where('lead_id', (int) $request->input('lead_id')))
            ->when($request->filled('assignee_role'), fn ($q) => $q->where('assignee_role', $request->string('assignee_role')))
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
        return response()->json($task->load($this->taskRelations()));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'assignee_role' => ['nullable', Rule::in(['advisor', 'psa'])],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed'])],
        ]);

        $data['created_by'] = $request->user()?->id;

        $task = Task::query()->create($data);

        return response()->json($task->load($this->taskRelations()), 201);
    }

    /**
     * Create one callback task per lead, assigned to that lead's advisor or PSA owner.
     */
    public function bulkFromLeads(Request $request)
    {
        $this->assertCanManageSalesTasks($request);

        $data = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer', 'exists:leads,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'assignee_role' => ['required', Rule::in(['advisor', 'psa'])],
        ]);

        $role = $data['assignee_role'];
        $ownerColumn = $role === 'advisor' ? 'advisor_owner_id' : 'psa_owner_id';
        $createdBy = $request->user()?->id;
        $created = [];
        $failed = [];

        $leads = Lead::query()
            ->whereIn('id', $data['lead_ids'])
            ->get(['id', 'student_name', 'advisor_owner_id', 'psa_owner_id'])
            ->keyBy('id');

        foreach ($data['lead_ids'] as $leadId) {
            $lead = $leads->get($leadId);
            if (! $lead) {
                $failed[] = ['lead_id' => $leadId, 'reason' => 'Lead not found'];
                continue;
            }

            $assignedTo = $lead->{$ownerColumn};
            if (! $assignedTo) {
                $failed[] = [
                    'lead_id' => $leadId,
                    'reason' => $role === 'advisor'
                        ? 'No advisor assigned to this lead'
                        : 'No PSA assigned to this lead',
                ];
                continue;
            }

            $task = Task::query()->create([
                'lead_id' => $lead->id,
                'assigned_to' => (int) $assignedTo,
                'assignee_role' => $role,
                'created_by' => $createdBy,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'status' => 'pending',
            ]);

            $created[] = $task->load($this->taskRelations());
        }

        return response()->json([
            'created' => $created,
            'failed' => $failed,
        ], 201);
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
            'assignee_role' => ['nullable', Rule::in(['advisor', 'psa'])],
        ]);

        $task->update($data);

        return response()->json($task->fresh()->load($this->taskRelations()));
    }

    public function destroy(Task $task)
    {
        $task->delete();

        return response()->json(['message' => 'Task deleted']);
    }
}
