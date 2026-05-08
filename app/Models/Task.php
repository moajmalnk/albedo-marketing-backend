<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = ['lead_id', 'assigned_to', 'title', 'description', 'status', 'due_at', 'completed_at'];
    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
