<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    protected $fillable = ['user_id', 'work_mode', 'check_in_at', 'check_out_at', 'net_minutes', 'session_number', 'day_date'];

    protected function casts(): array
    {
        return ['check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'day_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
