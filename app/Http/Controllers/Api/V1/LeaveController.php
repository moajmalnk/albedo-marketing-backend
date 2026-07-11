<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    /**
     * Display a listing of leave requests.
     */
    public function index(Request $request)
    {
        $actor = $request->user()->loadMissing('role');
        $roleKey = $actor->role?->key;

        $query = LeaveRequest::query()
            ->with(['user.role'])
            ->orderByDesc('from_date')
            ->orderByDesc('id');

        // Apply access control scopes
        if (in_array($roleKey, ['super_admin', 'admin'], true)) {
            // Super admins and admins see all leave requests
        } elseif ($roleKey === 'dept_head') {
            // Department heads only see telecaller leave requests in their department
            $dept = $actor->department;
            $query->whereHas('user', function ($q) use ($dept) {
                $q->where('department', $dept)
                  ->whereHas('role', function ($qr) {
                      $qr->where('key', 'telecaller');
                  });
            });
        } else {
            // Regular employees only see their own leave requests
            $query->where('user_id', $actor->id);
        }

        // Optional status / type filters (handled on backend for robustness)
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('leave_type') && $request->input('leave_type') !== 'All') {
            $query->where('leave_type', $request->input('leave_type'));
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created leave request in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'leave_type' => ['required', 'string', 'max:100'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'total_days' => ['required', 'numeric', 'min:0.1'],
            'reason' => ['required', 'string'],
        ]);

        $leave = LeaveRequest::query()->create([
            'user_id' => $request->user()->id,
            'leave_type' => $data['leave_type'],
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'total_days' => $data['total_days'],
            'reason' => $data['reason'],
            'status' => 'Pending',
            'date_applied' => now()->toDateString(),
        ]);

        return response()->json($leave->load('user.role'), 201);
    }

    /**
     * Update the specified leave request in storage.
     */
    public function update(Request $request, $id)
    {
        $actor = $request->user()->loadMissing('role');
        $roleKey = $actor->role?->key;

        // Only super_admin and admin can review/update leave requests
        if (! in_array($roleKey, ['super_admin', 'admin'], true)) {
            abort(403, 'You are not authorized to update leave requests.');
        }

        $leave = LeaveRequest::query()->findOrFail($id);

        $data = $request->validate([
            'status' => ['sometimes', 'required', 'string', Rule::in(['Pending', 'Approved', 'Rejected', 'Discussion'])],
            'admin_comment' => ['nullable', 'string'],
        ]);

        $leave->update($data);

        return response()->json($leave->fresh()->load('user.role'));
    }

    /**
     * Remove the specified leave request from storage.
     */
    public function destroy(Request $request, $id)
    {
        $leave = LeaveRequest::query()->findOrFail($id);

        // Only the owner of the leave request can delete it, and only if it's still pending
        if ($leave->user_id !== $request->user()->id) {
            abort(403, 'You can only cancel your own leave requests.');
        }

        if ($leave->status !== 'Pending') {
            abort(400, 'Only pending leave requests can be cancelled.');
        }

        $leave->delete();

        return response()->json(['message' => 'Leave request cancelled successfully.']);
    }
}
