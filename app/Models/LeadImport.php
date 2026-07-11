<?php

namespace App\Models;

use App\Traits\Auditable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadImport extends Model
{
    use Auditable;

    protected $fillable = [
        'file_name',
        'user_id',
        'source',
        'campaign',
        'campaign_id',
        'department',
        'total_rows',
        'accepted_count',
        'duplicate_count',
        'rejected_count',
        'failed_count',
        'started_at',
        'completed_at',
        'duration_seconds',
        'status',
        'error_file_path',
        'notes',
        'duplicate_strategy',
        'duplicate_criteria',
        'mapping_profile',
    ];

    protected $casts = [
        'mapping_profile' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(LeadImportRow::class, 'import_id');
    }
}
