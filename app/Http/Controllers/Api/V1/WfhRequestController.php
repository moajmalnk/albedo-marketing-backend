<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WfhRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WfhRequestController extends Controller
{
    /**
     * Display a listing of WFH requests.
     */
    public function index(Request $request)
    {
        $actor = $request->user()->loadMissing('role');
        $roleKey = $actor->role?->key;

        $query = WfhRequest::query()
            ->with(['user.role'])
            ->orderByDesc('from_date')
            ->orderByDesc('id');

        // Apply access control scopes
        if (in_array($roleKey, ['super_admin', 'admin'], true)) {
            // Super admins and admins see all WFH requests
        } elseif ($roleKey === 'dept_head') {
            // Department heads only see telecaller WFH requests in their department
            $dept = $actor->department;
            $query->whereHas('user', function ($q) use ($dept) {
                $q->where('department', $dept)
                  ->whereHas('role', function ($qr) {
                      $qr->where('key', 'telecaller');
                  });
            });
        } else {
            // Regular employees only see their own WFH requests
            $query->where('user_id', $actor->id);
        }

        // Optional status filter
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created WFH request in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string'],
        ]);

        $wfh = WfhRequest::query()->create([
            'user_id' => $request->user()->id,
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'reason' => $data['reason'],
            'status' => 'Pending',
            'date_applied' => now()->toDateString(),
        ]);

        return response()->json($wfh->load('user.role'), 201);
    }

    /**
     * Update the specified WFH request in storage.
     */
    public function update(Request $request, $id)
    {
        $actor = $request->user()->loadMissing('role');
        $roleKey = $actor->role?->key;

        // Only super_admin and admin can review/update WFH requests
        if (! in_array($roleKey, ['super_admin', 'admin'], true)) {
            abort(403, 'You are not authorized to update WFH requests.');
        }

        $wfh = WfhRequest::query()->findOrFail($id);

        $data = $request->validate([
            'status' => ['sometimes', 'required', 'string', Rule::in(['Pending', 'Approved', 'Rejected'])],
            'admin_note' => ['nullable', 'string'],
        ]);

        $wfh->update($data);

        return response()->json($wfh->fresh()->load('user.role'));
    }

    /**
     * Remove the specified WFH request from storage.
     */
    public function destroy(Request $request, $id)
    {
        $wfh = WfhRequest::query()->findOrFail($id);

        // Only the owner of the WFH request can delete it, and only if it's still pending
        if ($wfh->user_id !== $request->user()->id) {
            abort(403, 'You can only cancel your own WFH requests.');
        }

        if ($wfh->status !== 'Pending') {
            abort(400, 'Only pending WFH requests can be cancelled.');
        }

        $wfh->delete();

        return response()->json(['message' => 'WFH request cancelled successfully.']);
    }
}
