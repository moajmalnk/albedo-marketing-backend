<?php

namespace App\Models;

use App\Traits\Auditable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id',
        'work_mode',
        'check_in_at',
        'check_out_at',
        'break_started_at',
        'break_last_ended_at',
        'break_seconds',
        'net_minutes',
        'session_number',
        'is_final_session',
        'summary_leads_handled',
        'summary_calls_made',
        'summary_conversions',
        'summary_followups_completed',
        'summary_notes',
        'summary_issues',
        'day_date',
        'auto_closed',
    ];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'break_started_at' => 'datetime',
            'break_last_ended_at' => 'datetime',
            'break_seconds' => 'integer',
            'net_minutes' => 'integer',
            'session_number' => 'integer',
            'is_final_session' => 'boolean',
            'summary_leads_handled' => 'integer',
            'summary_calls_made' => 'integer',
            'summary_conversions' => 'integer',
            'summary_followups_completed' => 'integer',
            'day_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
