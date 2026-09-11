<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\LeadClosedReason;
use App\Models\LeadStage;
use App\Models\User;
use App\Services\PhoneNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeds Reshma's NATC marketing-pipeline sheet into leads.
 *
 * CSV statuses → marketing stages (exact sheet labels):
 *   NATC            → marketing_natc (label: NATC)
 *   NOT INTERESTED  → not_interested
 *   EXISTING        → existing + already_enrolled=true
 *   (blank)         → new_lead
 *
 * Prerequisites:
 *   php artisan db:seed --class=SyncMarketingPipelineSeeder
 *
 * Optional owner (marketing telecaller / marketer):
 *   RESHMA_LEADS_OWNER_EMAIL=someone@albedoedu.com php artisan db:seed --class=ReshmaNATCMarketingLeadSeeder
 *
 * Run:
 *   php artisan db:seed --class=ReshmaNATCMarketingLeadSeeder
 */
class ReshmaNATCMarketingLeadSeeder extends Seeder
{
    private const CSV_RELATIVE = 'data/reshma_leads_natc.csv';

    private const CAMPAIGN = 'Reshma NATC Import';

    private const COURSE_MAP = [
        'ACADEMICS' => 'Academics',
        'BASIC FOUNDATION' => 'Foundation',
        'ONLINE SCHOOLING' => 'ONLINE_SCHOOL',
        'ABACUS' => 'Other',
    ];

    private const SYLLABUS_MAP = [
        'STATE' => 'STATE',
        'CBSE' => 'CBSE',
        'ICSE' => 'ICSE',
        'OTHERS' => 'OTHERS',
    ];

    public function run(): void
    {
        $csvPath = database_path(self::CSV_RELATIVE);
        if (! is_readable($csvPath)) {
            $this->command?->error("CSV not found: {$csvPath}");

            return;
        }

        $stages = $this->resolveStages();
        if ($stages === null) {
            return;
        }

        $closedReasons = [
            'marketing_natc' => LeadClosedReason::query()->where('key', 'no_response')->value('id'),
            'not_interested' => LeadClosedReason::query()->where('key', 'not_interested')->value('id'),
            'existing' => LeadClosedReason::query()->where('key', 'duplicate')->value('id'),
        ];

        $ownerId = $this->resolveOwnerId();
        $rows = $this->readCsv($csvPath);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $byStage = [];

        DB::transaction(function () use (
            $rows,
            $stages,
            $closedReasons,
            $ownerId,
            &$created,
            &$updated,
            &$skipped,
            &$byStage
        ) {
            foreach ($rows as $index => $row) {
                $phoneRaw = trim((string) ($row['Lead Number'] ?? ''));
                $name = trim((string) ($row['Lead Name'] ?? ''));

                if ($phoneRaw === '' || $name === '') {
                    $skipped++;
                    $this->command?->warn('Row '.($index + 2).': missing name/phone — skipped.');

                    continue;
                }

                $phone = PhoneNormalizer::normalize($phoneRaw);
                if ($phone === '' || strlen($phone) < 10) {
                    $skipped++;
                    $this->command?->warn("Row ".($index + 2).": invalid phone '{$phoneRaw}' — skipped.");

                    continue;
                }

                $statusRaw = strtoupper(trim((string) ($row['LEAD STATUS'] ?? '')));
                [$stageKey, $alreadyEnrolled] = $this->mapStatus($statusRaw);
                $stage = $stages[$stageKey] ?? null;
                if (! $stage) {
                    $skipped++;
                    $this->command?->warn("Row ".($index + 2).": stage '{$stageKey}' missing — skipped.");

                    continue;
                }

                $enquiryAt = $this->parseEnquiryDate(trim((string) ($row['Enquiry Date'] ?? '')));
                $course = $this->mapCourse(trim((string) ($row['Course'] ?? '')));
                $syllabus = $this->mapSyllabus(trim((string) ($row['Syllabus'] ?? '')));
                $class = $this->mapClass(trim((string) ($row['Class'] ?? '')));
                $extraNote = trim((string) ($row['Column 1'] ?? ''));

                $isTerminal = in_array($stage->type, ['won', 'lost'], true);
                $closedReasonId = $closedReasons[$stageKey] ?? null;

                $notes = trim(implode("\n", array_filter([
                    'Imported from Reshma Leads - NATC.csv',
                    $statusRaw !== '' ? "Sheet status: {$statusRaw}" : 'Sheet status: (blank → New Lead)',
                    $extraNote !== '' ? "Sheet note: {$extraNote}" : null,
                ])));

                $payload = [
                    'student_name' => $name,
                    'phone' => $phone,
                    'whatsapp' => $phone,
                    'class' => $class,
                    'syllabus' => $syllabus,
                    'course' => $course,
                    'course_interested' => $course,
                    'enquiry_at' => $enquiryAt,
                    'stage_id' => $stage->id,
                    'status' => $stage->legacy_status ?: $stage->label,
                    'assigned_dept' => 'MARKETING',
                    'assignment_status' => $ownerId ? 'assigned' : 'waiting',
                    'owner_id' => $ownerId,
                    'campaign' => self::CAMPAIGN,
                    'source_group' => 'other',
                    'source_code' => 'RESHMA_NATC',
                    'already_enrolled' => $alreadyEnrolled,
                    'closed_reason_id' => $isTerminal ? $closedReasonId : null,
                    'closed_at' => $isTerminal ? ($enquiryAt ?? Carbon::now()) : null,
                    'notes_html' => $notes,
                    'priority' => 'normal',
                ];

                $lead = Lead::withTrashed()->updateOrCreate(['phone' => $phone], $payload);

                if ($lead->trashed()) {
                    $lead->restore();
                }

                if ($lead->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }

                $byStage[$stage->label] = ($byStage[$stage->label] ?? 0) + 1;
            }
        });

        $this->command?->info("Reshma NATC marketing leads: {$created} created, {$updated} updated, {$skipped} skipped.");
        if ($byStage !== []) {
            $this->command?->table(
                ['Marketing stage', 'Count'],
                collect($byStage)->map(fn ($count, $label) => [$label, $count])->values()->all()
            );
        }
    }

    /**
     * @return array<string, LeadStage>|null
     */
    private function resolveStages(): ?array
    {
        $keys = ['new_lead', 'not_interested', 'marketing_natc', 'existing'];
        $stages = LeadStage::query()
            ->whereIn('key', $keys)
            ->where('team', 'marketing')
            ->where('is_active', true)
            ->get()
            ->keyBy('key');

        $missing = array_values(array_diff($keys, $stages->keys()->all()));
        if ($missing !== []) {
            $this->command?->error(
                'Missing marketing stages: '.implode(', ', $missing).
                '. Run: php artisan db:seed --class=SyncMarketingPipelineSeeder'
            );

            return null;
        }

        return $stages->all();
    }

    private function resolveOwnerId(): ?int
    {
        $email = trim((string) env('RESHMA_LEADS_OWNER_EMAIL', ''));
        if ($email === '') {
            return null;
        }

        $userId = User::query()->where('email', $email)->value('id');
        if (! $userId) {
            $this->command?->warn("Owner email '{$email}' not found — leads will be unassigned.");

            return null;
        }

        return (int) $userId;
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $header = null;
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => trim((string) $h), $data);

                continue;
            }

            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? (string) $data[$i] : '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return array{0: string, 1: bool} [stageKey, alreadyEnrolled]
     */
    private function mapStatus(string $status): array
    {
        return match ($status) {
            'NATC' => ['marketing_natc', false],
            'NOT INTERESTED' => ['not_interested', false],
            'EXISTING' => ['existing', true],
            default => ['new_lead', false],
        };
    }

    private function mapCourse(string $course): ?string
    {
        if ($course === '') {
            return null;
        }

        $upper = strtoupper($course);

        return self::COURSE_MAP[$upper] ?? $course;
    }

    private function mapSyllabus(string $syllabus): ?string
    {
        if ($syllabus === '') {
            return null;
        }

        $upper = strtoupper($syllabus);

        return self::SYLLABUS_MAP[$upper] ?? $syllabus;
    }

    private function mapClass(string $class): ?string
    {
        if ($class === '') {
            return null;
        }

        if (preg_match('/^class\s+(.+)$/i', $class, $m)) {
            return trim($m[1]);
        }

        return $class;
    }

    private function parseEnquiryDate(string $raw): ?Carbon
    {
        if ($raw === '') {
            return null;
        }

        foreach (['n/j/Y', 'm/d/Y', 'n/j/y', 'm/d/y', 'Y-m-d'] as $format) {
            try {
                $dt = Carbon::createFromFormat($format, $raw);
                if ($dt !== false) {
                    return $dt->startOfDay();
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
