<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds mock leads across every active sales-pipeline stage (team = sales).
 *
 * Prerequisites: SyncSalesPipelineSeeder (or SeedBifurcatedPipelinesSeeder) so
 * sales stages exist.
 *
 * Run:
 *   php artisan db:seed --class=SyncSalesPipelineSeeder
 *   php artisan db:seed --class=SalesPipelineLeadSeeder
 */
class SalesPipelineLeadSeeder extends Seeder
{
    /** How many open-stage leads to create per stage. Terminal stages get 1. */
    private const OPEN_LEADS_PER_STAGE = 2;

    /** Phone prefix reserved for this seeder (idempotent upsert key). */
    private const PHONE_PREFIX = '+91988100';

    private const COURSES = ['Foundation', 'Academics', 'Crash', 'PENCIL_FOUNDATION', 'FOUNDATION_PLUS_ACADEMICS'];

    private const SOURCE_GROUPS = ['influence', 'performance', 'albedo', 'reference', 'other'];

    private const SOURCE_CODES = ['NSF_014', 'YT_003', 'WEB_ORG', 'STU_REF'];

    private const CITIES = ['Kochi', 'Trivandrum', 'Calicut', 'Thrissur', 'Kannur'];

    private const CLASSES = ['8', '9', '10', '11', '12'];

    private const FIRST_NAMES = [
        'Aarav', 'Diya', 'Ishaan', 'Meera', 'Rohan', 'Ananya', 'Kabir', 'Sara',
        'Vivaan', 'Nisha', 'Arjun', 'Priya', 'Aditya', 'Kavya', 'Rahul', 'Neha',
        'Dev', 'Anika', 'Vihaan', 'Ira', 'Reyansh', 'Tara', 'Ayaan', 'Myra',
        'Krish', 'Zara', 'Sai', 'Aisha', 'Om', 'Riya', 'Yash', 'Pooja', 'Nikhil', 'Sana',
    ];

    private const LAST_NAMES = [
        'Nair', 'Menon', 'Pillai', 'Krishnan', 'Thomas', 'Joseph', 'Kumar', 'Sharma',
        'Iyer', 'Rajan', 'Varma', 'Das', 'George', 'Mathew', 'Singh', 'Fernandez',
    ];

    public function run(): void
    {
        $stages = LeadStage::query()
            ->where('team', 'sales')
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        if ($stages->isEmpty()) {
            $this->command?->error('No active sales stages found. Run SyncSalesPipelineSeeder first.');

            return;
        }

        $owners = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('key', ['sales_head', 'team_lead', 'advisor', 'psa']))
            ->orderBy('id')
            ->get();

        if ($owners->isEmpty()) {
            $owners = User::query()->orderBy('id')->limit(3)->get();
        }

        $ownerIds = $owners->pluck('id')->values()->all();
        $phoneSeq = 1;
        $created = 0;
        $updated = 0;

        foreach ($stages as $stageIndex => $stage) {
            $count = in_array($stage->type, ['won', 'lost'], true)
                ? 1
                : self::OPEN_LEADS_PER_STAGE;

            for ($i = 0; $i < $count; $i++) {
                $phone = sprintf('%s%04d', self::PHONE_PREFIX, $phoneSeq);
                $nameIndex = ($phoneSeq - 1) % count(self::FIRST_NAMES);
                $lastIndex = ($phoneSeq - 1) % count(self::LAST_NAMES);
                $studentName = self::FIRST_NAMES[$nameIndex].' '.self::LAST_NAMES[$lastIndex];

                $enquiryAt = Carbon::now()->subDays(($stageIndex * 2) + $i + 1)->subHours($i * 3);
                $isTerminal = in_array($stage->type, ['won', 'lost'], true);
                $ownerId = $ownerIds === [] ? null : $ownerIds[($phoneSeq - 1) % count($ownerIds)];

                $payload = [
                    'student_name' => $studentName,
                    'phone' => $phone,
                    'whatsapp' => $phone,
                    'email' => strtolower(str_replace(' ', '.', $studentName)).'.mock@example.com',
                    'parent_name' => 'Parent of '.$studentName,
                    'parent_relation' => $phoneSeq % 2 === 0 ? 'Father' : 'Mother',
                    'class' => self::CLASSES[$phoneSeq % count(self::CLASSES)],
                    'syllabus' => $phoneSeq % 3 === 0 ? 'CBSE' : 'State',
                    'course' => self::COURSES[$phoneSeq % count(self::COURSES)],
                    'course_interested' => self::COURSES[$phoneSeq % count(self::COURSES)],
                    'city' => self::CITIES[$phoneSeq % count(self::CITIES)],
                    'district' => self::CITIES[$phoneSeq % count(self::CITIES)],
                    'state' => 'Kerala',
                    'country' => 'India',
                    'source_group' => self::SOURCE_GROUPS[$phoneSeq % count(self::SOURCE_GROUPS)],
                    'source_code' => self::SOURCE_CODES[$phoneSeq % count(self::SOURCE_CODES)],
                    'campaign' => 'Mock Sales Pipeline',
                    'connected_by' => $phoneSeq % 2 === 0 ? 'Inbound Call' : 'Inbound WhatsApp',
                    'enquiry_at' => $enquiryAt,
                    'stage_id' => $stage->id,
                    'status' => $stage->legacy_status ?: $stage->label,
                    'owner_id' => $ownerId,
                    'advisor_owner_id' => $ownerId,
                    'assigned_dept' => 'SALES',
                    'assignment_status' => $ownerId ? 'assigned' : 'waiting',
                    'priority' => $phoneSeq % 5 === 0 ? 'high' : 'normal',
                    'last_contacted_at' => $enquiryAt->copy()->addHours(6),
                    'next_action_at' => $isTerminal ? null : Carbon::now()->addDays(($i % 3) + 1),
                    'closed_at' => $isTerminal ? Carbon::now()->subDays($i + 1) : null,
                    'score' => 40 + (($phoneSeq * 7) % 55),
                ];

                $lead = Lead::query()->updateOrCreate(['phone' => $phone], $payload);

                if ($lead->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }

                $phoneSeq++;
            }
        }

        $this->command?->info("Sales pipeline mock leads: {$created} created, {$updated} updated across {$stages->count()} stages.");
        $this->command?->table(
            ['Stage', 'Type', 'Leads (mock phones)'],
            $stages->map(function (LeadStage $stage) {
                $count = Lead::query()
                    ->where('stage_id', $stage->id)
                    ->where('phone', 'like', self::PHONE_PREFIX.'%')
                    ->count();

                return [$stage->label, $stage->type, $count];
            })->all()
        );
    }
}
