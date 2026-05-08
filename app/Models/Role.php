<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['key', 'name', 'permission_level'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
