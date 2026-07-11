<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadScoringRule;
use App\Models\LeadScoreTier;
use Carbon\Carbon;

class LeadScoringService
{
    public function calculateScore(Lead $lead): void
    {
        $rules = LeadScoringRule::where('is_active', true)->get();
        $totalScore = 0;

        foreach ($rules as $rule) {
            if ($this->evaluateRule($lead, $rule)) {
                $totalScore += $rule->points;
            }
        }

        $lead->score = $totalScore;
        $lead->score_tier = $this->determineTier($totalScore);
        $lead->score_last_calculated_at = Carbon::now();
        
        // Save silently to avoid infinite loop if called from saving event
        $lead->saveQuietly();
    }

    private function evaluateRule(Lead $lead, LeadScoringRule $rule): bool
    {
        $fieldValue = $lead->{$rule->condition_field};
        
        switch ($rule->condition_operator) {
            case 'equals':
                return strtolower($fieldValue) === strtolower($rule->condition_value);
            case 'not_equals':
                return strtolower($fieldValue) !== strtolower($rule->condition_value);
            case 'contains':
                return stripos($fieldValue, $rule->condition_value) !== false;
            case 'is_valid':
                if ($rule->condition_field === 'email') {
                    return filter_var($fieldValue, FILTER_VALIDATE_EMAIL) !== false;
                }
                if ($rule->condition_field === 'phone') {
                    return preg_match('/^[0-9]{10}$/', $fieldValue) === 1;
                }
                return !empty($fieldValue);
            case 'is_invalid':
                if (empty($fieldValue)) return true;
                if ($rule->condition_field === 'email') {
                    return filter_var($fieldValue, FILTER_VALIDATE_EMAIL) === false;
                }
                if ($rule->condition_field === 'phone') {
                    return preg_match('/^[0-9]{10}$/', $fieldValue) !== 1;
                }
                return false;
            default:
                return false;
        }
    }

    private function determineTier(int $score): string
    {
        $tiers = LeadScoreTier::all();
        
        foreach ($tiers as $tier) {
            if ($score >= $tier->min_score && ($tier->max_score === null || $score <= $tier->max_score)) {
                return $tier->name;
            }
        }
        
        return 'Cold Lead';
    }

    public function recalculateAll(): void
    {
        Lead::chunk(100, function ($leads) {
            foreach ($leads as $lead) {
                $this->calculateScore($lead);
            }
        });
    }
}
