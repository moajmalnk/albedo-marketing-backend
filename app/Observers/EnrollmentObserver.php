<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Lead;
use App\Models\LeadStage;

class EnrollmentObserver
{
    public function created(Enrollment $enrollment): void
    {

        $this->syncLeadStage($enrollment);
    }

    public function updated(Enrollment $enrollment): void
    {

        $this->syncLeadStage($enrollment);
    }

    public function deleted(Enrollment $enrollment): void
    {
    }

    private function syncLeadStage(Enrollment $enrollment): void
    {
        $enrolledStageId = LeadStage::query()->where('key', 'enrolled')->value('id');
        if ($enrolledStageId) {
            Lead::query()->whereKey($enrollment->lead_id)->update(['stage_id' => $enrolledStageId]);
        }
    }
}
