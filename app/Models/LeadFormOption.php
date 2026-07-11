<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadFormOption extends Model
{
    protected $fillable = ['group_id', 'value', 'label', 'sort_order', 'is_active', 'meta'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meta' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LeadFormOptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(LeadFormOptionGroup::class, 'group_id');
    }
}
