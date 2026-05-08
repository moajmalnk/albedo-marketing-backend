<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use SoftDeletes;

    protected $fillable = ['lead_id','advisor_id','enrollment_type','admission_status','package_amount','spot_amount','fee_amount','balance_amount','payment_method','course_start_date','course_end_date','confirmed_at'];
    protected function casts(): array
    {
        return ['course_start_date' => 'date', 'course_end_date' => 'date', 'confirmed_at' => 'datetime'];
    }
}
