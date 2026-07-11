<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function events(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'owner_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        $ownerId = $request->filled('owner_id') ? (int) $request->input('owner_id') : null;

        $taskQuery = Task::query()
            ->whereBetween('due_at', [$from, $to])
            ->when($ownerId !== null, fn ($q) => $q->where('assigned_to', $ownerId))
            ->with(['lead:id,student_name'])
            ->orderBy('due_at');

        $assessmentQuery = Assessment::query()
            ->whereBetween('scheduled_at', [$from, $to])
            ->when($ownerId !== null, function ($q) use ($ownerId) {
                $q->whereHas('lead', fn ($lq) => $lq->where('owner_id', $ownerId));
            })
            ->with(['lead:id,student_name,owner_id'])
            ->orderBy('scheduled_at');

        $events = [];

        foreach ($taskQuery->get() as $task) {
            $events[] = [
                'id' => 'task-' . $task->id,
                'type' => 'task',
                'title' => $task->title,
                'starts_at' => optional($task->due_at)->toIso8601String(),
                'ends_at' => optional($task->due_at)?->copy()->addMinutes(30)->toIso8601String(),
                'lead_id' => $task->lead_id,
                'lead_name' => optional($task->lead)->student_name,
                'status' => $task->status,
                'owner_id' => $task->assigned_to,
            ];
        }

        foreach ($assessmentQuery->get() as $assessment) {
            $events[] = [
                'id' => 'assessment-' . $assessment->id,
                'type' => 'assessment',
                'title' => 'Assessment · ' . (optional($assessment->lead)->student_name ?: 'Lead #' . $assessment->lead_id),
                'starts_at' => optional($assessment->scheduled_at)->toIso8601String(),
                'ends_at' => optional($assessment->scheduled_at)?->copy()->addMinutes(45)->toIso8601String(),
                'lead_id' => $assessment->lead_id,
                'lead_name' => optional($assessment->lead)->student_name,
                'status' => $assessment->status,
                'owner_id' => optional($assessment->lead)->owner_id,
            ];
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['starts_at'], (string) $b['starts_at']));

        return response()->json(['data' => $events]);
    }
}
