<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnknownCall extends Model
{
    protected $fillable = ['call_id', 'direction', 'from_phone', 'to_phone', 'agent_extension', 'started_at', 'duration_sec', 'recording_url', 'disposition'];
    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }
}
