<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DuplicateRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_phone',
        'match_email',
        'match_whatsapp',
        'default_action',
        'reengagement_rule',
    ];

    protected $casts = [
        'match_phone' => 'boolean',
        'match_email' => 'boolean',
        'match_whatsapp' => 'boolean',
    ];
}
