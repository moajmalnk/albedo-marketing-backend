<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'lead_id',
        'advisor_id',
        'enrollment_type',
        'admission_status',
        'package_amount',
        'spot_amount',
        'fee_amount',
        'balance_amount',
        'payment_method',
        'course_start_date',
        'course_end_date',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'package_amount' => 'decimal:2',
            'spot_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'course_start_date' => 'date',
            'course_end_date' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
