<?php

namespace App\Models;

use App\Traits\Auditable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use Auditable;

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

    /**
     * If spot_amount exceeds recorded payments (e.g. opening DP on create),
     * insert a payment for the gap so totals stay payment-ledger based.
     */
    public function ensureOpeningPayment(?int $receivedBy = null): ?Payment
    {
        $existingPaid = (float) $this->payments()->sum('amount');
        $orphan = round((float) $this->spot_amount - $existingPaid, 2);
        if ($orphan <= 0) {
            return null;
        }

        $receiverId = $receivedBy ?? auth()->id();
        if (! $receiverId) {
            return null;
        }

        $method = $this->payment_method;
        if (! in_array($method, ['cash', 'upi', 'card', 'bank_transfer', 'emi'], true)) {
            $method = 'upi';
        }

        return $this->payments()->create([
            'amount' => $orphan,
            'method' => $method,
            'reference' => 'Initial spot / DP',
            'received_at' => $this->confirmed_at ?? $this->created_at ?? now(),
            'received_by' => $receiverId,
        ]);
    }

    /** Recompute spot_amount / balance_amount / admission_status from payment rows. */
    public function syncAmountsFromPayments(): void
    {
        $totalPaid = round((float) $this->payments()->sum('amount'), 2);
        $balance = max(0, round((float) $this->package_amount - $totalPaid, 2));
        $status = $this->admission_status;
        if ($balance <= 0) {
            $status = 'full';
        } elseif ($status === 'full') {
            $status = 'partial';
        }

        $this->update([
            'spot_amount' => $totalPaid,
            'balance_amount' => $balance,
            'admission_status' => $status,
        ]);
    }
}
