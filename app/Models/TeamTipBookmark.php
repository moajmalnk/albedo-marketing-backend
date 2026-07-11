<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamTipBookmark extends Model
{
    protected $table = 'team_tip_bookmarks';

    protected $fillable = [
        'team_tip_id',
        'user_id',
    ];

    public function teamTip(): BelongsTo
    {
        return $this->belongsTo(TeamTip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
