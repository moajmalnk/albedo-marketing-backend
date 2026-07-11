<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeComment extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'challenge_comments';

    protected $fillable = [
        'challenge_id',
        'user_id',
        'type',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(MarketingChallenge::class, 'challenge_id');
    }
}
