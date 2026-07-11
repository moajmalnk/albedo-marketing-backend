<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'office_start_time',
        'office_end_time',
        'grace_period_minutes',
        'late_threshold_minutes',
        'early_checkout_threshold_minutes',
        'half_day_hours',
        'weekend_days',
        'updated_by',
    ];

    protected $casts = [
        'weekend_days' => 'array',
        'half_day_hours' => 'float',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
