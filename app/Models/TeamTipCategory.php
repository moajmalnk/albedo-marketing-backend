<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;


class TeamTipCategory extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'team_tip_categories';

    protected $fillable = [
        'name',
        'slug',
    ];
}
