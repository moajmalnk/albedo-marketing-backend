<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class LeadStage extends Model
{
    protected $fillable = ['key', 'label', 'group', 'order', 'color', 'is_terminal'];

    protected function casts(): array
    {
        return ['is_terminal' => 'boolean'];
    }

    public function leads(): HasMany { return $this->hasMany(Lead::class, 'stage_id'); }
}
