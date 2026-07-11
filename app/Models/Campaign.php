<?php

namespace App\Models;

use App\Traits\Auditable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use Auditable;

    use HasFactory;

    protected $fillable = [
        'name',
        'platform',
        'status',
        'budget',
        'spend',
        'start_date',
        'end_date',
        'owner_id',
        'department',
        'created_by',
        'updated_by',
    ];

    /**
     * Soft-linking: Get leads that match this campaign's name.
     */
    public function leads()
    {
        return $this->hasMany(Lead::class, 'campaign_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
