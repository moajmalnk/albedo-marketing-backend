<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamTipRead extends Model
{
    protected $table = 'team_tip_reads';

    protected $fillable = [
        'team_tip_id',
        'user_id',
        'read_at',
        'first_read_at',
        'read_count',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'first_read_at' => 'datetime',
        ];
    }

    public function teamTip(): BelongsTo
    {
        return $this->belongsTo(TeamTip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
