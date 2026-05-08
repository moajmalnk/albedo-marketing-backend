<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    protected $fillable = ['lead_id', 'activity_id', 'scheduled_at', 'student_profile', 'parent_availability', 'notes', 'status'];
    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }
}
