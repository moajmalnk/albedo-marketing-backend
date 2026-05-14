<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = ['student_name','phone','alternate_phone','whatsapp','whatsapp_id','email','children_count','already_enrolled','parent_name','parent_relation','class','syllabus','course','subjects','school','city','district','state','country','pincode','source_group','source_code','campaign','connected_by','enquiry_at','stage_id','status','owner_id','captured_by_user_id','assigned_dept','is_read_only','priority','dnd','next_action_at','last_contacted_at','created_by','generated_by_user_id','notes_html'];

    protected function casts(): array
    {
        return [
            'subjects' => 'array',
            'is_read_only' => 'boolean',
            'dnd' => 'boolean',
            'already_enrolled' => 'boolean',
            'children_count' => 'integer',
            'next_action_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'enquiry_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo { return $this->belongsTo(LeadStage::class, 'stage_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function generatedBy(): BelongsTo { return $this->belongsTo(User::class, 'generated_by_user_id'); }
    public function activities(): HasMany { return $this->hasMany(LeadActivity::class); }
    public function transitions(): HasMany { return $this->hasMany(LeadStageTransition::class); }
}
