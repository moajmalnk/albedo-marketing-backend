<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;


class TeamTip extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'team_tips';

    protected $fillable = [
        'title',
        'description',
        'content',
        'category_id',
        'department',
        'pinned',
        'attachments',
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
            'attachments' => 'array',
            'date_sent' => 'date',
            'pinned' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TeamTipCategory::class, 'category_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(TeamTipRead::class);
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(TeamTipBookmark::class);
    }
}
