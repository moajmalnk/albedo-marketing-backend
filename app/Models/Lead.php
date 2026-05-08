<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = ['student_name','phone','whatsapp','email','parent_name','parent_relation','class','syllabus','course','subjects','school','city','district','state','country','pincode','source_group','source_code','campaign','stage_id','status','owner_id','assigned_dept','is_read_only','priority','dnd','next_action_at','created_by'];

    protected function casts(): array
    {
        return ['subjects' => 'array', 'is_read_only' => 'boolean', 'dnd' => 'boolean', 'next_action_at' => 'datetime'];
    }

    public function stage(): BelongsTo { return $this->belongsTo(LeadStage::class, 'stage_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function activities(): HasMany { return $this->hasMany(LeadActivity::class); }
    public function transitions(): HasMany { return $this->hasMany(LeadStageTransition::class); }
}
