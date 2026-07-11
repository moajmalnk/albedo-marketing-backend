<?php

namespace App\Models;

use App\Traits\Auditable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\LeadFormOption;

class ProductTarget extends Model
{
    use Auditable;

    use HasFactory;

    protected $fillable = [
        'name',
        'monthly_target',
        'month',
        'year',
        'status',
    ];

    protected static function booted()
    {
        static::saved(function ($product) {
            $group = \App\Models\LeadFormOptionGroup::where('slug', 'course')->first();
            if (!$group) return;

            if ($product->status === 'Active') {
                LeadFormOption::updateOrCreate(
                    [
                        'group_id' => $group->id,
                        'value' => $product->name,
                    ],
                    [
                        'label' => $product->name,
                        'is_active' => true,
                    ]
                );
            } else {
                LeadFormOption::where('group_id', $group->id)
                    ->where('value', $product->name)
                    ->update(['is_active' => false]);
            }
        });

        static::deleted(function ($product) {
            $group = \App\Models\LeadFormOptionGroup::where('slug', 'course')->first();
            if (!$group) return;

            LeadFormOption::where('group_id', $group->id)
                ->where('value', $product->name)
                ->delete();
        });
    }
}
