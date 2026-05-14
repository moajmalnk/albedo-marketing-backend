<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadFormOptionGroup extends Model
{
    protected $fillable = ['slug', 'label'];

    /**
     * @return HasMany<LeadFormOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(LeadFormOption::class, 'group_id')->orderBy('sort_order')->orderBy('id');
    }

    public function activeOptions(): HasMany
    {
        return $this->options()->where('is_active', true);
    }
}
