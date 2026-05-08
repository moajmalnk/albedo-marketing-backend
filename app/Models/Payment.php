<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['enrollment_id', 'amount', 'method', 'reference', 'received_at', 'received_by'];
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
