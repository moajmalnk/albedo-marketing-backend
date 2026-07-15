<?php

namespace App\Models;

use App\Traits\Auditable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use Auditable;

    use SoftDeletes;

    protected $fillable = [
        'student_name', 'phone', 'capture_qualification', 'alternate_phone', 'whatsapp',
        'whatsapp_id', 'email', 'children_count', 'already_enrolled', 'parent_name',
        'parent_relation', 'class', 'syllabus', 'course', 'subjects', 'school',
        'course_interested', 'qualification', 'preferred_campus',
        'city', 'district', 'state', 'country', 'pincode', 'source_group', 'source_code',
        'campaign', 'connected_by', 'enquiry_at', 'stage_id', 'closed_reason_id', 'closed_by', 'closed_at', 'status',
        'owner_id', 'captured_by_user_id', 'assigned_dept', 'is_read_only', 'priority',
        'dnd', 'next_action_at', 'last_contacted_at', 'created_by', 'generated_by_user_id', 'deleted_by', 'updated_by',
        'assigned_by', 'assigned_at', 'assignment_notes',
        'notes_html', 'score', 'score_tier', 'score_last_calculated_at',
        'assignment_status', 'routing_failed', 'telecaller_owner_id', 'psa_owner_id', 'advisor_owner_id', 'campaign_id',
    ];

    public ?string $assignment_type = null;
    public ?string $assignment_reason = null;

    protected static function booted()
    {
        static::saving(function ($lead) {
            if ($lead->isDirty('stage_id') && $lead->stage_id) {
                $stage = \App\Models\LeadStage::find($lead->stage_id);
                if ($stage && $stage->legacy_status) {
                    $lead->status = $stage->legacy_status;
                }
            } elseif ($lead->isDirty('status') && ! $lead->isDirty('stage_id')) {
                $stage = \App\Models\LeadStage::where('legacy_status', $lead->status)
                    ->orWhere('label', $lead->status)
                    ->first();
                if ($stage) {
                    $lead->stage_id = $stage->id;
                }
            }

            if ($lead->isDirty('owner_id')) {
                if ($lead->owner_id) {
                    if (!$lead->assignment_status || $lead->assignment_status === 'waiting') {
                        $lead->assignment_status = 'assigned';
                    }
                    $lead->routing_failed = false;
                } else {
                    $lead->assignment_status = 'waiting';
                }
            }
        });

        static::saved(function ($lead) {
            app(\App\Services\LeadScoringService::class)->calculateScore($lead);

            if ($lead->wasChanged('owner_id')) {
                $prevOwnerId = $lead->getOriginal('owner_id');
                $newOwnerId = $lead->owner_id;

                $type = $lead->assignment_type 
                    ?? request()->input('assignment_type') 
                    ?? ($prevOwnerId === null ? 'Initial Assignment' : 'Manual Reassignment');

                $reason = $lead->assignment_reason 
                    ?? request()->input('reason') 
                    ?? 'Manual Lead Assignment';

                $deptId = null;
                if ($lead->assigned_dept) {
                    $deptId = \App\Models\Department::where('code', $lead->assigned_dept)->value('id')
                        ?? \App\Models\Department::where('name', $lead->assigned_dept)->value('id');
                }

                \App\Models\LeadAssignment::create([
                    'lead_id' => $lead->id,
                    'previous_owner_id' => $prevOwnerId,
                    'new_owner_id' => $newOwnerId,
                    'assigned_by' => auth()->id() ?? $lead->created_by,
                    'assignment_type' => $type,
                    'reason' => $reason,
                    'department_id' => $deptId,
                    'campaign_id' => $lead->campaign_id,
                ]);
            }
        });
    }

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
            'score_last_calculated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo { return $this->belongsTo(LeadStage::class, 'stage_id'); }
    public function closedReason(): BelongsTo { return $this->belongsTo(LeadClosedReason::class, 'closed_reason_id'); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function telecallerOwner(): BelongsTo { return $this->belongsTo(User::class, 'telecaller_owner_id'); }
    public function psaOwner(): BelongsTo { return $this->belongsTo(User::class, 'psa_owner_id'); }
    public function advisorOwner(): BelongsTo { return $this->belongsTo(User::class, 'advisor_owner_id'); }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class, 'campaign_id'); }
    public function generatedBy(): BelongsTo { return $this->belongsTo(User::class, 'generated_by_user_id'); }
    public function activities(): HasMany { return $this->hasMany(LeadActivity::class); }
    public function transitions(): HasMany { return $this->hasMany(LeadStageTransition::class); }
    public function documents(): HasMany { return $this->hasMany(LeadDocument::class); }
}
