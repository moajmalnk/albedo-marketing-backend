<?php

namespace Database\Seeders;

use App\Models\LeadStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SyncMarketingPipelineSeeder
 *
 * Idempotent seeder — safe to run on production at any time.
 * - Ensures canonical marketing stages exist with correct data.
 * - Deactivates any marketing-team stages NOT in the canonical list.
 * - Does NOT touch sales stages.
 *
 * Sheet-aligned lost stages: NATC, NOT INTERESTED, EXISTING.
 * Note: sales already owns key `natc`, so marketing NATC uses `marketing_natc`.
 *
 * Run: php artisan db:seed --class=SyncMarketingPipelineSeeder
 */
class SyncMarketingPipelineSeeder extends Seeder
{
    /**
     * Canonical marketing pipeline stages.
     * Order is significant — it defines the funnel progression.
     */
    private const MARKETING_STAGES = [
        // ── Active / Open Stages ─────────────────────────────────────
        [
            'key' => 'new_lead',
            'label' => 'New Lead',
            'type' => 'open',
            'color' => '#3b82f6',
            'sla_hours' => 4,
            'legacy_status' => 'New Lead',
        ],
        [
            'key' => 'attempted_contact_1',
            'label' => 'Attempted Contact 1',
            'type' => 'open',
            'color' => '#06b6d4',
            'sla_hours' => 24,
            'legacy_status' => 'Attempted Contact 1',
        ],
        [
            'key' => 'attempted_contact_2',
            'label' => 'Attempted Contact 2',
            'type' => 'open',
            'color' => '#0ea5e9',
            'sla_hours' => 24,
            'legacy_status' => 'Attempted Contact 2',
        ],
        [
            'key' => 'attempted_contact_3',
            'label' => 'Attempted Contact 3',
            'type' => 'open',
            'color' => '#6366f1',
            'sla_hours' => 24,
            'legacy_status' => 'Attempted Contact 3',
        ],
        [
            'key' => 'connected',
            'label' => 'Connected / Talking',
            'type' => 'open',
            'color' => '#8b5cf6',
            'sla_hours' => 24,
            'legacy_status' => 'Connected / Talking',
        ],
        [
            'key' => 'qualified_for_sales',
            'label' => 'Qualified for Sales',
            'type' => 'open',
            'color' => '#10b981',
            'sla_hours' => 12,
            'legacy_status' => 'Qualified for Sales',
        ],
        [
            'key' => 'nurture',
            'label' => 'Nurture / Follow-up',
            'type' => 'open',
            'color' => '#f59e0b',
            'sla_hours' => 72,
            'legacy_status' => 'Nurture / Follow-up',
        ],
        // ── Terminal / Won ────────────────────────────────────────────
        [
            'key' => 'handed_to_sales',
            'label' => 'Handed Off to Sales',
            'type' => 'won',
            'color' => '#22c55e',
            'sla_hours' => null,
            'legacy_status' => 'Handed Off to Sales',
        ],
        // ── Terminal / Lost (sheet statuses from Reshma NATC CSV) ─────
        // Sales already owns key `natc`; marketing uses `marketing_natc`.
        [
            'key' => 'marketing_natc',
            'label' => 'NATC',
            'type' => 'lost',
            'color' => '#78716c',
            'sla_hours' => null,
            'legacy_status' => 'NATC',
        ],
        [
            'key' => 'not_interested',
            'label' => 'NOT INTERESTED',
            'type' => 'lost',
            'color' => '#6b7280',
            'sla_hours' => null,
            'legacy_status' => 'NOT INTERESTED',
        ],
        [
            'key' => 'existing',
            'label' => 'EXISTING',
            'type' => 'lost',
            'color' => '#a8a29e',
            'sla_hours' => null,
            'legacy_status' => 'EXISTING',
        ],
        [
            'key' => 'junk',
            'label' => 'Junk / Invalid',
            'type' => 'lost',
            'color' => '#ef4444',
            'sla_hours' => null,
            'legacy_status' => 'Junk / Invalid',
        ],
        [
            'key' => 'duplicate',
            'label' => 'Duplicate',
            'type' => 'lost',
            'color' => '#991b1b',
            'sla_hours' => null,
            'legacy_status' => 'Duplicate',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $canonicalKeys = collect(self::MARKETING_STAGES)->pluck('key')->all();

            foreach (self::MARKETING_STAGES as $index => $stage) {
                LeadStage::updateOrCreate(
                    ['key' => $stage['key']],
                    [
                        'label' => $stage['label'],
                        'type' => $stage['type'],
                        'team' => 'marketing',
                        'is_terminal' => in_array($stage['type'], ['won', 'lost'], true),
                        'color' => $stage['color'],
                        'order' => $index + 1,
                        'group' => 'active',
                        'is_active' => true,
                        'sla_hours' => $stage['sla_hours'],
                        'legacy_status' => $stage['legacy_status'],
                    ]
                );
            }

            $deactivated = LeadStage::where('team', 'marketing')
                ->whereNotIn('key', $canonicalKeys)
                ->update(['is_active' => false]);

            $this->command?->info('✓ Upserted '.count(self::MARKETING_STAGES).' canonical marketing stages.');

            if ($deactivated > 0) {
                $this->command?->warn("⚠ Deactivated {$deactivated} obsolete marketing stage(s) not in the canonical list.");
            }

            $this->command?->table(
                ['Order', 'Key', 'Label', 'Team', 'Type', 'Active'],
                LeadStage::where('team', 'marketing')
                    ->orderBy('order')
                    ->get(['order', 'key', 'label', 'team', 'type', 'is_active'])
                    ->map(fn ($s) => [
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
