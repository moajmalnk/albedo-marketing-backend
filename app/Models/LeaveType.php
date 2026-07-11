<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'days_allowed_per_year',
        'is_paid',
        'color',
    ];

    protected $casts = [
        'days_allowed_per_year' => 'integer',
        'is_paid' => 'boolean',
    ];
}
