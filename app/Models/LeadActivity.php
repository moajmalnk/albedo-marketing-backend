<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class LeadActivity extends Model
{
    protected $fillable = ['lead_id','user_id','type','direction','connected','outcome','duration_sec','recording_url','comments','payload','occurred_at'];
    protected function casts(): array
    {
        return ['connected' => 'boolean', 'payload' => 'array', 'occurred_at' => 'datetime'];
    }

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
