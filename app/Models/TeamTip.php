<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamTip extends Model
{
    protected $table = 'team_tips';

    protected $fillable = [
        'title',
        'description',
        'sent_to',
        'sent_by',
        'sent_by_role',
        'date_sent',
        'status',
        'priority',
        'read_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sent_to' => 'array',
            'date_sent' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
