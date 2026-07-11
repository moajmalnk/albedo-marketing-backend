<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    public function index()
    {
        $settings = SystemSetting::all()->pluck('value', 'key');
        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable|string',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $request) {
            foreach ($data['settings'] as $key => $value) {
                $setting = SystemSetting::where('key', $key)->first();
                if ($setting) {
                    if ($setting->value !== $value) {
                        $setting->update(['value' => $value]);
                    }
                } else {
                    SystemSetting::create(['key' => $key, 'value' => $value]);
                }
            }
        });

        return response()->json(['message' => 'Settings updated successfully']);
    }
}
