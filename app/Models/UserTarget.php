<?php

namespace App\Models;

use App\Traits\Auditable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTarget extends Model
{
    use Auditable;

    use HasFactory;

    protected $fillable = [
        'user_id',
        'target_type',
        'period',
        'target_value',
        'product_name',
        'month',
        'year',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
