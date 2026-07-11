<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AttendanceSettingController extends Controller
{
    public function show()
    {
        // Require super_admin or admin to view
        if (!auth()->user()->hasRole(['super_admin', 'admin', 'department_head'])) {
            abort(403);
        }

        $setting = AttendanceSetting::first();

        if (!$setting) {
            $setting = AttendanceSetting::create([
                'office_start_time' => '09:00:00',
                'office_end_time' => '18:00:00',
                'grace_period_minutes' => 15,
                'late_threshold_minutes' => 15,
                'early_checkout_threshold_minutes' => 30,
                'half_day_hours' => 4.00,
                'weekend_days' => ['Saturday', 'Sunday'],
            ]);
        }

        return response()->json($setting);
    }

    public function update(Request $request)
    {
        if (!auth()->user()->hasRole(['super_admin', 'admin'])) {
            abort(403);
        }

        $validated = $request->validate([
            'office_start_time' => ['required', 'date_format:H:i:s'],
            'office_end_time' => ['required', 'date_format:H:i:s'],
            'grace_period_minutes' => ['required', 'integer', 'min:0'],
            'late_threshold_minutes' => ['required', 'integer', 'min:0'],
            'early_checkout_threshold_minutes' => ['required', 'integer', 'min:0'],
            'half_day_hours' => ['required', 'numeric', 'min:1', 'max:12'],
            'weekend_days' => ['present', 'array'],
            'weekend_days.*' => ['string', 'in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday'],
        ]);

        $setting = AttendanceSetting::first();
        if (!$setting) {
            $setting = new AttendanceSetting();
        }

        $setting->fill($validated);
        $setting->updated_by = auth()->id();
        $setting->save();

        return response()->json($setting);
    }
}
