<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadScoringRule extends Model
{
    protected $fillable = [
        'name',
        'description',
        'condition_field',
        'condition_operator',
        'condition_value',
        'points',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'points' => 'integer',
    ];
}
