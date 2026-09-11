<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'lead_id',
        'invoice_number',
        'invoice_date',
        'student_name',
        'class_label',
        'sessions',
        'hours_per_session',
        'amount_per_session',
        'study_materials',
        'tuition_fee',
        'admission_fee',
        'total_fee',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'sessions' => 'integer',
            'amount_per_session' => 'decimal:2',
            'study_materials' => 'decimal:2',
            'tuition_fee' => 'decimal:2',
            'admission_fee' => 'decimal:2',
            'total_fee' => 'decimal:2',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function packageAmount(): float
    {
        return round((float) $this->sessions * (float) $this->amount_per_session, 2);
    }

    public function packageTotal(): float
    {
        return round($this->packageAmount() + (float) $this->study_materials, 2);
    }

    public function recalculateTotals(): void
    {
        $this->tuition_fee = round((float) $this->sessions * (float) $this->amount_per_session, 2);
        $this->total_fee = round((float) $this->tuition_fee + (float) $this->admission_fee, 2);
    }

    public static function defaultClassLabel(Lead $lead): string
    {
        $class = trim((string) ($lead->class ?? ''));
        $syllabus = trim((string) ($lead->syllabus ?? ''));

        if ($class !== '' && $syllabus !== '') {
            return "{$class} ( {$syllabus} )";
        }

        return $class !== '' ? $class : ($syllabus !== '' ? $syllabus : '');
    }
}
