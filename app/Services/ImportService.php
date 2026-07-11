<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\LeadStage;
use App\Models\AuditLog;
use App\Models\User;
use App\Jobs\ImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ImportService
{
    protected $validationService;
    protected $duplicateService;
    protected $leadService;

    public function __construct(
        ValidationService $validationService,
        DuplicateService $duplicateService,
        LeadService $leadService
    ) {
        $this->validationService = $validationService;
        $this->duplicateService = $duplicateService;
        $this->leadService = $leadService;
    }

    /**
     * Map a raw row array to target fields based on mapping profile.
     */
    public function mapRow(array $row, array $mapping): array
    {
        $mapped = [];
        foreach ($mapping as $targetKey => $sourceKey) {
            if (!empty($sourceKey) && isset($row[$sourceKey])) {
                $mapped[$targetKey] = $row[$sourceKey];
            }
        }
        return $mapped;
    }

    /**
     * Perform a dry-run validation on the provided rows.
     */
    public function dryRun(array $rows, array $mapping, string $criteria): array
    {
        $validCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;
        $previewRows = [];

        foreach ($rows as $index => $row) {
            $mapped = $this->mapRow($row, $mapping);
            
            // Validate row
            $errors = $this->validationService->validateRow($mapped);
            if (!empty($errors)) {
                $invalidCount++;
                $previewRows[] = [
                    'row_number' => $index + 1,
                    'payload' => $mapped,
                    'status' => 'invalid',
                    'errors' => $errors,
                ];
                continue;
            }

            // Check duplicates
            $existing = $this->duplicateService->findExistingLead(
                $mapped['phone'] ?? '',
                $mapped['email'] ?? null,
                $criteria
            );

            if ($existing) {
                $duplicateCount++;
                $previewRows[] = [
                    'row_number' => $index + 1,
                    'payload' => $mapped,
                    'status' => 'duplicate',
                    'existing_lead' => [
                        'id' => $existing->id,
                        'name' => $existing->student_name,
                        'phone' => $existing->phone,
                    ],
                ];
            } else {
                $validCount++;
                $previewRows[] = [
                    'row_number' => $index + 1,
                    'payload' => $mapped,
                    'status' => 'valid',
                ];
            }
        }

        return [
            'total' => count($rows),
            'valid' => $validCount,
            'duplicate' => $duplicateCount,
            'invalid' => $invalidCount,
            'rows' => array_slice($previewRows, 0, 50), // Return preview of first 50 rows
        ];
    }

    /**
     * Import rows. If rows count > 100, queues background job.
     */
    public function startImport(User $user, array $rows, array $payload): LeadImport
    {
        $import = LeadImport::create([
            'file_name' => $payload['file_name'] ?? 'import.csv',
            'user_id' => $user->id,
            'source' => $payload['source'],
            'campaign' => $payload['campaign'] ?? null,
            'campaign_id' => $payload['campaign_id'] ?? null,
            'department' => $payload['department'] ?? null,
            'total_rows' => count($rows),
            'duplicate_strategy' => $payload['duplicate_strategy'] ?? 'skip',
            'duplicate_criteria' => $payload['duplicate_criteria'] ?? 'phone',
            'mapping_profile' => $payload['mapping_profile'] ?? [],
            'status' => 'Pending',
        ]);

        $ip = request()->ip();
        $userAgent = request()->userAgent();

        if (count($rows) > 100) {
            $import->update(['status' => 'Queued']);
            ImportJob::dispatch($import, $rows, $ip, $userAgent);
        } else {
            $this->executeImport($import, $rows, $ip, $userAgent);
        }

        return $import;
    }

    /**
     * Run the import synchronously.
     */
    public function executeImport(LeadImport $import, array $rows, ?string $ip = null, ?string $userAgent = null): void
    {
        $startTime = Carbon::now();
        $import->update([
            'status' => 'Processing',
            'started_at' => $startTime,
        ]);

        $accepted = 0;
        $duplicate = 0;
        $rejected = 0;
        $failed = 0;

        $newLeadStageId = LeadStage::query()->where('key', 'new_lead')->value('id');
        $mapping = $import->mapping_profile;
        $criteria = $import->duplicate_criteria;
        $strategy = $import->duplicate_strategy;

        foreach ($rows as $index => $row) {
            DB::beginTransaction();
            try {
                $mapped = $this->mapRow($row, $mapping);
                
                // Validate
                $errors = $this->validationService->validateRow($mapped);
                if (!empty($errors)) {
                    $rejected++;
                    LeadImportRow::create([
                        'import_id' => $import->id,
                        'row_number' => $index + 1,
                        'payload' => $mapped,
                        'error_message' => implode(' ', $errors),
                        'status' => 'rejected',
                    ]);
                    DB::commit();
                    continue;
                }

                $phone = PhoneNormalizer::normalize($mapped['phone']);
                $email = $mapped['email'] ?? null;

                // Check duplicates
                $existing = $this->duplicateService->findExistingLead($phone, $email, $criteria);

                if ($existing) {
                    if ($strategy === 'skip') {
                        $duplicate++;
                        LeadImportRow::create([
                            'import_id' => $import->id,
                            'row_number' => $index + 1,
                            'payload' => $mapped,
                            'error_message' => 'Duplicate lead skipped.',
                            'status' => 'duplicate',
                        ]);
                        DB::commit();
                        continue;
                    }

                    if ($strategy === 'update' || $strategy === 'merge') {
                        $this->duplicateService->processDuplicate($existing, [
                            'student_name' => $mapped['student_name'] ?? null,
                            'email' => $email,
                            'class' => $mapped['class'] ?? null,
                            'syllabus' => $mapped['syllabus'] ?? null,
                            'city' => $mapped['city'] ?? null,
                            'district' => $mapped['district'] ?? null,
                            'state' => $mapped['state'] ?? null,
                            'country' => $mapped['country'] ?? null,
                            'source_code' => $import->source,
                            'campaign' => $import->campaign,
                            'campaign_id' => $import->campaign_id,
                            'source_group' => $mapped['source_group'] ?? 'other',
                        ], $strategy);

                        $accepted++;
                        DB::commit();
                        continue;
                    }

                    // For 'force', we need to insert anyway, but phone is unique in DB.
                    // We modify the phone with a suffix (e.g. 9123456789-dup) to bypass constraint.
                    if ($strategy === 'force') {
                        $phone = substr($phone, 0, 15) . '-dup' . rand(1, 9);
                    }
                }

                $assignedDept = 'SALES';
                if ($import->department) {
                    $deptUpper = strtoupper($import->department);
                    if (str_contains($deptUpper, 'MARKETING') || $deptUpper === 'PM' || $deptUpper === 'IM') {
                        $assignedDept = 'MARKETING';
                    }
                }

                // Insert new lead
                $this->leadService->createLead([
                    'student_name' => $mapped['student_name'] ?? 'Unknown',
                    'phone' => $phone,
                    'whatsapp' => $mapped['whatsapp'] ?? null,
                    'email' => $email,
                    'class' => $mapped['class'] ?? null,
                    'syllabus' => $mapped['syllabus'] ?? null,
                    'city' => $mapped['city'] ?? null,
                    'district' => $mapped['district'] ?? null,
                    'state' => $mapped['state'] ?? null,
                    'country' => $mapped['country'] ?? null,
                    'source_group' => $mapped['source_group'] ?? 'other',
                    'source_code' => $import->source,
                    'campaign' => $import->campaign,
                    'campaign_id' => $import->campaign_id,
                    'created_by' => $import->user_id,
                    'generated_by_user_id' => $import->user_id,
                    'stage_id' => $newLeadStageId,
                    'assigned_dept' => $assignedDept,
                ]);

                $accepted++;
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $failed++;
                LeadImportRow::create([
                    'import_id' => $import->id,
                    'row_number' => $index + 1,
                    'payload' => $row,
                    'error_message' => $e->getMessage(),
                    'status' => 'failed',
                ]);
            }
        }

        $endTime = Carbon::now();
        $duration = $startTime->diffInSeconds($endTime);

        $import->update([
            'status' => 'Completed',
            'accepted_count' => $accepted,
            'duplicate_count' => $duplicate,
            'rejected_count' => $rejected,
            'failed_count' => $failed,
            'completed_at' => $endTime,
            'duration_seconds' => $duration,
        ]);

        // Audit Logging
    }
}
