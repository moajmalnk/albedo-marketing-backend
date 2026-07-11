<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::with('actor.role');

        $from = $request->query('from');
        if ($from) {
            // Ensure full day coverage if just date is provided
            $query->whereDate('created_at', '>=', $from);
        }

        $to = $request->query('to');
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        $action = $request->query('action');
        if ($action && $action !== 'All') {
            $query->where('action', 'like', '%' . $action . '%');
        }

        $userFilter = $request->query('user_id');
        // The frontend passes names right now, so we need to match by first_name or let frontend pass IDs.
        // It's safer to just search by name if it's not numeric, but let's assume frontend will pass exact string.
        if ($userFilter && $userFilter !== 'All') {
             $query->whereHas('actor', function ($q) use ($userFilter) {
                 $q->where('first_name', 'like', '%' . $userFilter . '%')
                   ->orWhere('last_name', 'like', '%' . $userFilter . '%');
             });
        }

        $search = $request->query('search');
        if ($search) {
             $query->where(function ($q) use ($search) {
                 $q->where('action', 'like', '%' . $search . '%')
                   ->orWhere('entity_type', 'like', '%' . $search . '%')
                   ->orWhere('entity_id', 'like', '%' . $search . '%');
             });
        }

        $logs = $query->orderByDesc('created_at')->limit(1000)->get();

        return response()->json($logs->map(function ($log) {
            $actor = $log->actor;
            $name = $actor ? trim($actor->first_name . ' ' . $actor->last_name) : 'System';
            $role = $actor?->role?->name ?? 'System';

            $detailsStr = '';
            
            $newVals = is_array($log->new_values) ? $log->new_values : (json_decode($log->new_values, true) ?? []);
            $oldVals = is_array($log->old_values) ? $log->old_values : (json_decode($log->old_values, true) ?? []);

            if (!empty($newVals)) {
                $changes = [];
                foreach ($newVals as $key => $value) {
                    if ($key === 'updated_at') continue; // Hide noisy timestamp updates
                    
                    $oldValue = $oldVals[$key] ?? null;
                    if ($oldValue != $value) {
                        $valStr = is_array($value) ? 'array' : (string)$value;
                        $oldStr = is_array($oldValue) ? 'array' : (string)$oldValue;
                        
                        $cleanKey = ucwords(str_replace('_', ' ', $key));
                        
                        if ($key === 'last_login_at') {
                            $changes[] = "User logged in";
                        } elseif ($oldValue === null) {
                            $changes[] = "Set $cleanKey to '$valStr'";
                        } else {
                            $changes[] = "Changed $cleanKey from '$oldStr' to '$valStr'";
                        }
                    }
                }
                
                if (empty($changes)) {
                    $detailsStr = "Updated record";
                } else {
                    $detailsStr = implode(' • ', $changes);
                }
            } else {
                $detailsStr = $log->action;
            }

            return [
                'id' => 'AUD-' . str_pad((string) $log->id, 4, '0', STR_PAD_LEFT),
                'timestamp' => $log->created_at->format('Y-m-d H:i:s'),
                'action' => $log->action,
                'performedBy' => $name,
                'role' => $role,
                'target' => class_basename($log->entity_type) . ' #' . $log->entity_id,
                'details' => $detailsStr,
                'ipAddress' => $log->ip ?? 'Unknown',
            ];
        }));
    }
}
