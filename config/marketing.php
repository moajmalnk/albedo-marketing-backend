<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monthly lead intake target (marketing goal ring)
    |--------------------------------------------------------------------------
    |
    | Used when no per-product targets table exists. Progress is computed as
    | month-to-date lead count (optionally filtered) vs this target, capped at 100%.
    |
    */
    'monthly_lead_target' => (int) env('MARKETING_MONTHLY_LEAD_TARGET', 500),

    /*
    | Stages counted as "qualified" for marketing funnel charts and KPIs.
    | Aligned with enrolled / late-funnel readiness (not the legacy mock "Qualified" string).
    |
    */
    'qualified_stage_keys' => ['enrolled', 'itb'],

];
