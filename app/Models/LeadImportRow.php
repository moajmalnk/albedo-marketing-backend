<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadImportRow extends Model
{
    protected $fillable = [
        'import_id',
        'row_number',
        'payload',
        'error_message',
        'status',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(LeadImport::class, 'import_id');
    }
}
