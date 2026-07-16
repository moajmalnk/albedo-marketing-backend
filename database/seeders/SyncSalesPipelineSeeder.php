<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LeadStage;
use Illuminate\Support\Facades\DB;

/**
 * SyncSalesPipelineSeeder
 *
 * Idempotent seeder — safe to run on production at any time.
 * - Ensures exactly 17 defined sales stages exist with correct data.
 * - Deactivates any sales-team stages NOT in the canonical list.
 * - Does NOT touch marketing stages.
 *
 * Run: php artisan db:seed --class=SyncSalesPipelineSeeder
 */
class SyncSalesPipelineSeeder extends Seeder
{
    /**
     * Canonical sales pipeline stages.
     * Order is significant — it defines the funnel progression.
     */
    private const SALES_STAGES = [
        // ── Active / Open Stages ─────────────────────────────────────
        [
            'key'       => 'sales_new_lead',
            'label'     => 'New Lead',
            'type'      => 'open',
            'color'     => '#3b82f6',
            'sla_hours' => 2,
        ],
        [
            'key'       => 'prospect',
            'label'     => 'Prospect',
            'type'      => 'open',
            'color'     => '#8b5cf6',
            'sla_hours' => 24,
        ],
        [
            'key'       => 'first_call_nifc',
            'label'     => 'First call NIFC',
            'type'      => 'open',
            'color'     => '#f59e0b',
            'sla_hours' => 24,
        ],
        [
            'key'       => 'demo_required',
            'label'     => 'Demo Required',
            'type'      => 'open',
            'color'     => '#ec4899',
            'sla_hours' => 48,
        ],
        [
            'key'       => 'assessment_booked',
            'label'     => 'ASSESSMENT BOOKED',
            'type'      => 'open',
            'color'     => '#06b6d4',
            'sla_hours' => 48,
        ],
        [
            'key'       => 'assessment_done',
            'label'     => 'ASSESSMENT DONE',
            'type'      => 'open',
            'color'     => '#10b981',
            'sla_hours' => 24,
        ],
        [
            'key'       => 'interested_to_buy',
            'label'     => 'Interested To Buy (ITB)',
            'type'      => 'open',
            'color'     => '#84cc16',
            'sla_hours' => 24,
        ],
        [
            'key'       => 'follow_up',
            'label'     => 'Follow up',
            'type'      => 'open',
            'color'     => '#f97316',
            'sla_hours' => 48,
        ],
        [
            'key'       => 'may_buy_later',
            'label'     => 'May Buy Later',
            'type'      => 'open',
            'color'     => '#eab308',
            'sla_hours' => 72,
        ],
        // ── Terminal / Won ────────────────────────────────────────────
        [
            'key'       => 'sales_enrolled',
            'label'     => 'Enrolled',
            'type'      => 'won',
            'color'     => '#22c55e',
            'sla_hours' => null,
        ],
        // ── Terminal / Lost ───────────────────────────────────────────
        [
            'key'       => 'nifc',
            'label'     => 'Not Interested In Full Course (NIFC)',
            'type'      => 'lost',
            'color'     => '#ef4444',
            'sla_hours' => null,
        ],
        [
            'key'       => 'natc',
            'label'     => 'Not Able To Connect (NATC)',
            'type'      => 'lost',
            'color'     => '#dc2626',
            'sla_hours' => null,
        ],
        [
            'key'       => 'dnp',
            'label'     => 'Do Not Picked (DNP)',
            'type'      => 'lost',
            'color'     => '#b91c1c',
            'sla_hours' => null,
        ],
        [
            'key'       => 'disqualified',
            'label'     => 'Disqualified',
            'type'      => 'lost',
            'color'     => '#991b1b',
            'sla_hours' => null,
        ],
        [
            'key'       => 'invalid_junk',
            'label'     => 'Invalid/ Junk',
            'type'      => 'lost',
            'color'     => '#7f1d1d',
            'sla_hours' => null,
        ],
        [
            'key'       => 'duplicate_lead',
            'label'     => 'DUPLICATE LEAD',
            'type'      => 'lost',
            'color'     => '#450a0a',
            'sla_hours' => null,
        ],
        [
            'key'       => 'job_enquiry',
            'label'     => 'JOB ENQUIRY',
            'type'      => 'lost',
            'color'     => '#a3a3a3',
            'sla_hours' => null,
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $canonicalKeys = collect(self::SALES_STAGES)->pluck('key')->all();

            // 1. Upsert every canonical sales stage
            foreach (self::SALES_STAGES as $index => $stage) {
                LeadStage::updateOrCreate(
                    ['key' => $stage['key']],
                    [
                        'label'       => $stage['label'],
                        'type'        => $stage['type'],
                        'team'        => 'sales',
                        'is_terminal' => in_array($stage['type'], ['won', 'lost']),
                        'color'       => $stage['color'],
                        'order'       => 100 + $index,   // offset keeps sales after marketing stages
                        'group'       => 'active',
                        'is_active'   => true,
                        'sla_hours'   => $stage['sla_hours'],
                    ]
                );
            }

            // 2. Deactivate any sales-team stages that are no longer canonical
            $deactivated = LeadStage::where('team', 'sales')
                ->whereNotIn('key', $canonicalKeys)
                ->update(['is_active' => false]);

            $this->command->info("✓ Upserted " . count(self::SALES_STAGES) . " canonical sales stages.");

            if ($deactivated > 0) {
                $this->command->warn("⚠ Deactivated {$deactivated} obsolete sales stage(s) not in the canonical list.");
            }

            // 3. Print final state for verification
            $this->command->table(
                ['Order', 'Key', 'Label', 'Team', 'Type', 'Active'],
                LeadStage::where('team', 'sales')
                    ->orderBy('order')
                    ->get(['order', 'key', 'label', 'team', 'type', 'is_active'])
                    ->map(fn($s) => [
                        $s->order,
                        $s->key,
                        $s->label,
                        $s->team,
                        $s->type,
                        $s->is_active ? '✓' : '✗',
                    ])
                    ->toArray()
            );
        });
    }
}
