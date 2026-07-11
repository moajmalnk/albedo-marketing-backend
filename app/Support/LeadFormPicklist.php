<?php

namespace App\Support;

use App\Models\LeadFormOption;

class LeadFormPicklist
{
    /**
     * Distinct active option values for a lead_form_option_groups.slug.
     *
     * @return list<string>
     */
    public static function activeValuesForSlug(string $slug): array
    {
        return LeadFormOption::query()
            ->whereHas('group', fn ($q) => $q->where('slug', $slug))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('value')
            ->unique()
            ->values()
            ->all();
    }
}
