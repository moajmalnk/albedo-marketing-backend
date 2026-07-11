<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadScoreTier extends Model
{
    protected $fillable = [
        'name',
        'min_score',
        'max_score',
    ];

    protected $casts = [
        'min_score' => 'integer',
        'max_score' => 'integer',
    ];
}
