<?php

namespace App\Services;

use App\Models\Lead;
use App\Support\LeadChannelClassifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Server-side pivot for sales-pipeline leads (LeadSquared-style report builder).
 */
class SalesPivotAnalyticsService
{
    public const NO_VALUE = '[No Value]';

    public const MAX_ROWS = 5;

    public const MAX_COLUMNS = 1;

    public const MAX_VALUES = 25;

    /** @var array<string, array{expr: string, joins?: list<string>}> */
    private const DIMENSIONS = [
        'stage' => [
            'expr' => "COALESCE(NULLIF(TRIM(lead_stages.label), ''), '".self::NO_VALUE."')",
            'joins' => ['lead_stages'],
        ],
        'source_group' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.source_group), ''), '".self::NO_VALUE."')",
        ],
        'source_code' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.source_code), ''), '".self::NO_VALUE."')",
        ],
        'channel' => [
            'expr' => "CASE
                WHEN leads.whatsapp_id IS NOT NULL OR LOWER(COALESCE(leads.source_code, '')) LIKE '%whatsapp%' OR leads.source_code = 'whatsapp' THEN 'WhatsApp'
                WHEN LOWER(COALESCE(leads.connected_by, '')) LIKE '%call%' OR LOWER(COALESCE(leads.source_code, '')) LIKE '%call%' THEN 'Call'
                WHEN LOWER(COALESCE(leads.source_code, '')) LIKE '%message%' OR LOWER(COALESCE(leads.source_code, '')) LIKE '%sms%' OR LOWER(COALESCE(leads.connected_by, '')) LIKE '%message%' THEN 'Message'
                ELSE 'Form'
            END",
        ],
        'country' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.country), ''), '".self::NO_VALUE."')",
        ],
        'state' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.state), ''), '".self::NO_VALUE."')",
        ],
        'district' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.district), ''), '".self::NO_VALUE."')",
        ],
        'course' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.course), ''), NULLIF(TRIM(leads.course_interested), ''), '".self::NO_VALUE."')",
        ],
        'syllabus' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.syllabus), ''), '".self::NO_VALUE."')",
        ],
        'class' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.class), ''), '".self::NO_VALUE."')",
        ],
        'sub_brand' => [
            'expr' => "COALESCE(NULLIF(TRIM(owner_users.sub_brand), ''), NULLIF(TRIM(generated_users.sub_brand), ''), '".self::NO_VALUE."')",
            'joins' => ['owner_users', 'generated_users'],
        ],
        'priority' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.priority), ''), '".self::NO_VALUE."')",
        ],
        'campaign' => [
            'expr' => "COALESCE(NULLIF(TRIM(campaigns.name), ''), NULLIF(TRIM(leads.campaign), ''), '".self::NO_VALUE."')",
            'joins' => ['campaigns'],
        ],
        'utm_medium' => [
            'expr' => "CASE
                WHEN LOWER(COALESCE(leads.campaign, '')) LIKE '%cpc%' OR LOWER(COALESCE(leads.connected_by, '')) LIKE '%cpc%' THEN 'cpc'
                WHEN LOWER(COALESCE(leads.campaign, '')) LIKE '%organic%' OR LOWER(COALESCE(leads.connected_by, '')) LIKE '%organic%' THEN 'organic'
                WHEN LOWER(COALESCE(leads.campaign, '')) LIKE '%social%' OR LOWER(COALESCE(leads.connected_by, '')) LIKE '%social%' THEN 'social'
                WHEN LOWER(COALESCE(leads.campaign, '')) LIKE '%email%' OR LOWER(COALESCE(leads.connected_by, '')) LIKE '%email%' THEN 'email'
                WHEN LOWER(COALESCE(leads.campaign, '')) LIKE '%referral%' OR LOWER(COALESCE(leads.connected_by, '')) LIKE '%referral%' THEN 'referral'
                ELSE '".self::NO_VALUE."'
            END",
        ],
        'device' => [
            'expr' => "CASE
                WHEN LOWER(COALESCE(leads.connected_by, '')) LIKE '%desktop%' OR LOWER(COALESCE(leads.campaign, '')) LIKE '%desktop%' THEN 'Desktop'
                WHEN LOWER(COALESCE(leads.connected_by, '')) LIKE '%mobile%' OR LOWER(COALESCE(leads.campaign, '')) LIKE '%mobile%' THEN 'Mobile'
                WHEN LOWER(COALESCE(leads.connected_by, '')) LIKE '%tablet%' OR LOWER(COALESCE(leads.campaign, '')) LIKE '%tablet%' THEN 'Tablet'
                ELSE '".self::NO_VALUE."'
            END",
        ],
        'assessment_status' => [
            'expr' => "CASE
                WHEN LOWER(COALESCE(leads.notes_html, '')) LIKE '%assessment%completed%' OR LOWER(COALESCE(leads.notes_html, '')) LIKE '%assessment done%' THEN 'Completed'
                WHEN LOWER(COALESCE(leads.notes_html, '')) LIKE '%reschedul%' THEN 'Rescheduled'
                WHEN LOWER(COALESCE(leads.notes_html, '')) LIKE '%not interested%' THEN 'Not Interested'
                WHEN LOWER(COALESCE(leads.notes_html, '')) LIKE '%missed%' THEN 'Missed'
                WHEN lead_stages.key IN ('assessment_done', 'assessment_booked') THEN COALESCE(lead_stages.label, '".self::NO_VALUE."')
                ELSE '".self::NO_VALUE."'
            END",
            'joins' => ['lead_stages'],
        ],
        'closed_reason' => [
            'expr' => "COALESCE(NULLIF(TRIM(lead_closed_reasons.label), ''), '".self::NO_VALUE."')",
            'joins' => ['lead_closed_reasons'],
        ],
        'score_tier' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.score_tier), ''), '".self::NO_VALUE."')",
        ],
        'created_month' => [
            'expr' => "DATE_FORMAT(leads.created_at, '%Y-%m')",
        ],
        'created_year' => [
            'expr' => "DATE_FORMAT(leads.created_at, '%Y')",
        ],
        'department' => [
            'expr' => "COALESCE(NULLIF(TRIM(leads.assigned_dept), ''), '".self::NO_VALUE."')",
        ],
        'unassigned' => [
            'expr' => "CASE WHEN leads.owner_id IS NULL THEN 'Unassigned' ELSE 'Assigned' END",
        ],
        'owner' => [
            'expr' => "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(owner_users.first_name, ''), ' ', COALESCE(owner_users.last_name, ''))), ''), '".self::NO_VALUE."')",
            'joins' => ['owner_users'],
        ],
        'psa' => [
            'expr' => "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(psa_users.first_name, ''), ' ', COALESCE(psa_users.last_name, ''))), ''), '".self::NO_VALUE."')",
            'joins' => ['psa_users'],
        ],
        'advisor' => [
            'expr' => "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(advisor_users.first_name, ''), ' ', COALESCE(advisor_users.last_name, ''))), ''), '".self::NO_VALUE."')",
            'joins' => ['advisor_users'],
        ],
    ];

    /** @var array<string, string> */
    private const VALUE_FIELDS = [
        'id' => 'leads.id',
        'score' => 'leads.score',
    ];

    /** @var array<string, string> */
    private const AGGREGATIONS = [
        'count' => 'COUNT',
        'sum' => 'SUM',
        'avg' => 'AVG',
    ];

    /**
     * @param  array{
     *   rows?: list<string>,
     *   columns?: list<string>,
     *   values?: list<array{field?: string, aggregation?: string}>,
     *   filters?: array<string, mixed>,
     *   page?: int,
     *   per_page?: int
     * }  $payload
     * @return array<string, mixed>
     */
    public function pivot(array $payload): array
    {
        $rowKeys = array_values(array_filter(
            array_map('strval', $payload['rows'] ?? []),
            fn ($k) => $k !== ''
        ));
        $columnKeys = array_values(array_filter(
            array_map('strval', $payload['columns'] ?? []),
            fn ($k) => $k !== ''
        ));
        $values = $payload['values'] ?? [['field' => 'id', 'aggregation' => 'count']];
        if ($values === []) {
            $values = [['field' => 'id', 'aggregation' => 'count']];
        }

        if (count($rowKeys) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['rows' => 'At most '.self::MAX_ROWS.' row dimensions allowed.']);
        }
        if (count($columnKeys) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages(['columns' => 'At most '.self::MAX_COLUMNS.' column dimension allowed.']);
        }
        if (count($values) > self::MAX_VALUES) {
            throw ValidationException::withMessages(['values' => 'At most '.self::MAX_VALUES.' values allowed.']);
        }

        foreach ([...$rowKeys, ...$columnKeys] as $key) {
            if (! isset(self::DIMENSIONS[$key])) {
                throw ValidationException::withMessages(['dimensions' => "Unknown dimension: {$key}"]);
            }
        }

        $normalizedValues = [];
        foreach ($values as $i => $value) {
            $field = (string) ($value['field'] ?? 'id');
            $agg = strtolower((string) ($value['aggregation'] ?? 'count'));
            if (! isset(self::VALUE_FIELDS[$field])) {
                throw ValidationException::withMessages(["values.{$i}.field" => "Unknown value field: {$field}"]);
            }
            if (! isset(self::AGGREGATIONS[$agg])) {
                throw ValidationException::withMessages(["values.{$i}.aggregation" => "Unknown aggregation: {$agg}"]);
            }
            if ($agg !== 'count' && $field === 'id') {
                throw ValidationException::withMessages(["values.{$i}" => 'Lead ID only supports count aggregation.']);
            }
            $normalizedValues[] = [
                'field' => $field,
                'aggregation' => $agg,
                'alias' => 'metric_'.$i,
                'label' => $this->valueLabel($field, $agg),
            ];
        }

        $page = max(1, (int) ($payload['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($payload['per_page'] ?? 100)));
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

        $dimensionKeys = [...$rowKeys, ...$columnKeys];
        $joins = $this->collectJoins($dimensionKeys);

        $query = DB::table('leads')
            ->whereNull('leads.deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('lead_stages')
                    ->whereColumn('lead_stages.id', 'leads.stage_id')
                    ->where('lead_stages.team', 'sales');
            });

        $this->applyJoins($query, $joins);
        $this->applyFilters($query, $filters);

        // No dimensions: single grand-total cell
        if ($dimensionKeys === []) {
            $selects = [];
            foreach ($normalizedValues as $value) {
                $selects[] = $this->metricSelect($value).' as '.$value['alias'];
            }
            $row = (clone $query)->selectRaw(implode(', ', $selects))->first();
            $primary = $normalizedValues[0]['alias'];
            $grand = (float) ($row->{$primary} ?? 0);

            return [
                'columns' => [],
                'rows' => [[
                    'keys' => [],
                    'cells' => [],
                    'total' => $grand,
                    'metrics' => $this->extractMetrics($row, $normalizedValues),
                ]],
                'column_totals' => [],
                'grand_total' => $grand,
                'value_labels' => array_column($normalizedValues, 'label'),
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total_row_groups' => 1,
                    'last_page' => 1,
                    'records_retrieved' => 1,
                ],
            ];
        }

        $selectParts = [];
        $dimAliases = [];
        foreach ($dimensionKeys as $i => $key) {
            $alias = 'dim_'.$i;
            $expr = self::DIMENSIONS[$key]['expr'];
            $selectParts[] = "{$expr} as {$alias}";
            $dimAliases[] = $alias;
        }

        $valueSelects = [];
        foreach ($normalizedValues as $value) {
            $valueSelects[] = $this->metricSelect($value).' as '.$value['alias'];
        }

        // Subquery avoids MySQL ONLY_FULL_GROUP_BY rejecting COALESCE/TRIM expressions
        // that reference bare columns (error 1055).
        $inner = (clone $query)->selectRaw(implode(', ', [
            ...$selectParts,
            'leads.id as pivot_lead_id',
            'leads.score as pivot_score',
        ]));

        $outerSelects = [...$dimAliases];
        foreach ($normalizedValues as $value) {
            $outerSelects[] = $this->metricSelectOuter($value).' as '.$value['alias'];
        }

        $rawRows = DB::query()
            ->fromSub($inner, 'sales_pivot_base')
            ->selectRaw(implode(', ', $outerSelects))
            ->groupBy($dimAliases)
            ->get();

        $colCount = count($columnKeys);
        $rowDimCount = count($rowKeys);
        $primaryAlias = $normalizedValues[0]['alias'];

        $columnHeaders = [];
        $grouped = [];

        foreach ($rawRows as $raw) {
            $rowKeyParts = [];
            for ($i = 0; $i < $rowDimCount; $i++) {
                $rowKeyParts[] = $this->displayDim($raw->{'dim_'.$i} ?? null);
            }
            $rowKey = implode("\0", $rowKeyParts);

            $colLabel = null;
            if ($colCount > 0) {
                $colLabel = $this->displayDim($raw->{'dim_'.$rowDimCount} ?? null);
                $columnHeaders[$colLabel] = true;
            }

            if (! isset($grouped[$rowKey])) {
                $grouped[$rowKey] = [
                    'keys' => $rowKeyParts,
                    'cells' => [],
                    'total' => 0.0,
                    'metrics' => [],
                ];
            }

            $metric = (float) ($raw->{$primaryAlias} ?? 0);
            if ($colLabel !== null) {
                $grouped[$rowKey]['cells'][$colLabel] = ($grouped[$rowKey]['cells'][$colLabel] ?? 0) + $metric;
            }
            $grouped[$rowKey]['total'] += $metric;

            // Keep first-value metrics aggregate for multi-metric display on row total
            foreach ($normalizedValues as $value) {
                $alias = $value['alias'];
                $grouped[$rowKey]['metrics'][$alias] = ($grouped[$rowKey]['metrics'][$alias] ?? 0) + (float) ($raw->{$alias} ?? 0);
            }
        }

        $columns = array_keys($columnHeaders);
        natcasesort($columns);
        $columns = array_values($columns);

        $rowList = array_values($grouped);
        usort($rowList, function ($a, $b) {
            return strcasecmp(implode(' / ', $a['keys']), implode(' / ', $b['keys']));
        });

        $totalRowGroups = count($rowList);
        $lastPage = max(1, (int) ceil($totalRowGroups / $perPage));
        $page = min($page, $lastPage);
        $slice = array_slice($rowList, ($page - 1) * $perPage, $perPage);

        $columnTotals = [];
        $grandTotal = 0.0;
        foreach ($rowList as $row) {
            $grandTotal += $row['total'];
            foreach ($row['cells'] as $col => $val) {
                $columnTotals[$col] = ($columnTotals[$col] ?? 0) + $val;
            }
        }

        return [
            'columns' => $columns,
            'rows' => array_map(function (array $row) {
                return [
                    'keys' => $row['keys'],
                    'cells' => $row['cells'],
                    'total' => round($row['total'], 2),
                    'metrics' => array_map(fn ($v) => round((float) $v, 2), $row['metrics']),
                ];
            }, $slice),
            'column_totals' => array_map(fn ($v) => round((float) $v, 2), $columnTotals),
            'grand_total' => round($grandTotal, 2),
            'value_labels' => array_column($normalizedValues, 'label'),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_row_groups' => $totalRowGroups,
                'last_page' => $lastPage,
                'records_retrieved' => count($slice),
            ],
        ];
    }

    /** @return list<string> */
    public static function dimensionKeys(): array
    {
        return array_keys(self::DIMENSIONS);
    }

    private function valueLabel(string $field, string $agg): string
    {
        $fieldLabel = $field === 'id' ? 'Lead ID' : ucfirst($field);
        $aggLabel = match ($agg) {
            'sum' => 'Sum',
            'avg' => 'Avg',
            default => 'Count',
        };

        return "{$aggLabel}({$fieldLabel})";
    }

    private function metricSelect(array $value): string
    {
        $fn = self::AGGREGATIONS[$value['aggregation']];
        $col = self::VALUE_FIELDS[$value['field']];
        if ($value['aggregation'] === 'count') {
            return "COUNT({$col})";
        }

        return "{$fn}({$col})";
    }

    /** Aggregations against the pivot subquery aliases. */
    private function metricSelectOuter(array $value): string
    {
        if ($value['field'] === 'id' || $value['aggregation'] === 'count') {
            return 'COUNT(pivot_lead_id)';
        }

        $fn = self::AGGREGATIONS[$value['aggregation']];

        return "{$fn}(pivot_score)";
    }

    private function displayDim(mixed $value): string
    {
        if ($value === null) {
            return self::NO_VALUE;
        }
        $str = trim((string) $value);

        return $str === '' ? self::NO_VALUE : $str;
    }

    /**
     * @param  list<string>  $dimensionKeys
     * @return list<string>
     */
    private function collectJoins(array $dimensionKeys): array
    {
        $joins = [];
        foreach ($dimensionKeys as $key) {
            foreach (self::DIMENSIONS[$key]['joins'] ?? [] as $join) {
                $joins[$join] = true;
            }
        }

        return array_keys($joins);
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  list<string>  $joins
     */
    private function applyJoins($query, array $joins): void
    {
        // Always join stages when filtering sales; also needed for stage dimension
        if (! in_array('lead_stages', $joins, true)) {
            // optional left join only when needed for expressions; sales filter uses whereExists
        }

        foreach ($joins as $join) {
            match ($join) {
                'lead_stages' => $query->leftJoin('lead_stages', 'lead_stages.id', '=', 'leads.stage_id'),
                'lead_closed_reasons' => $query->leftJoin('lead_closed_reasons', 'lead_closed_reasons.id', '=', 'leads.closed_reason_id'),
                'campaigns' => $query->leftJoin('campaigns', 'campaigns.id', '=', 'leads.campaign_id'),
                'owner_users' => $query->leftJoin('users as owner_users', 'owner_users.id', '=', 'leads.owner_id'),
                'generated_users' => $query->leftJoin('users as generated_users', 'generated_users.id', '=', 'leads.generated_by_user_id'),
                'telecaller_users' => $query->leftJoin('users as telecaller_users', 'telecaller_users.id', '=', 'leads.telecaller_owner_id'),
                'psa_users' => $query->leftJoin('users as psa_users', 'psa_users.id', '=', 'leads.psa_owner_id'),
                'advisor_users' => $query->leftJoin('users as advisor_users', 'advisor_users.id', '=', 'leads.advisor_owner_id'),
                default => null,
            };
        }
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['status_filters']) && is_array($filters['status_filters'])) {
            $labels = array_values(array_filter(array_map('strval', $filters['status_filters'])));
            if ($labels !== []) {
                $query->whereExists(function ($q) use ($labels) {
                    $q->select(DB::raw(1))
                        ->from('lead_stages')
                        ->whereColumn('lead_stages.id', 'leads.stage_id')
                        ->whereIn('lead_stages.label', $labels);
                });
            }
        } elseif (! empty($filters['status_filter']) && is_string($filters['status_filter'])) {
            $label = $filters['status_filter'];
            $query->whereExists(function ($q) use ($label) {
                $q->select(DB::raw(1))
                    ->from('lead_stages')
                    ->whereColumn('lead_stages.id', 'leads.stage_id')
                    ->where('lead_stages.label', $label);
            });
        }

        if (! empty($filters['source_groups']) && is_array($filters['source_groups'])) {
            $groups = array_values(array_filter(array_map('strval', $filters['source_groups'])));
            if ($groups !== []) {
                $query->whereIn('leads.source_group', $groups);
            }
        } elseif (! empty($filters['source_group']) && $filters['source_group'] !== 'All') {
            $query->where('leads.source_group', $filters['source_group']);
        }

        if (! empty($filters['source_codes']) && is_array($filters['source_codes'])) {
            $codes = array_values(array_filter(array_map('strval', $filters['source_codes'])));
            if ($codes !== []) {
                $query->whereIn('leads.source_code', $codes);
            }
        } elseif (! empty($filters['source_code']) && $filters['source_code'] !== 'All') {
            $query->where('leads.source_code', $filters['source_code']);
        }

        $ownerIdMap = [
            'owner_ids' => 'owner_id',
            'telecaller_owner_ids' => 'telecaller_owner_id',
            'psa_owner_ids' => 'psa_owner_id',
            'advisor_owner_ids' => 'advisor_owner_id',
        ];
        foreach ($ownerIdMap as $plural => $column) {
            if (! empty($filters[$plural]) && is_array($filters[$plural])) {
                $ids = array_values(array_filter(array_map('intval', $filters[$plural])));
                if ($ids !== []) {
                    $query->whereIn('leads.'.$column, $ids);
                }
            } elseif (isset($filters[$column]) && $filters[$column] !== '' && $filters[$column] !== 'All') {
                $query->where('leads.'.$column, (int) $filters[$column]);
            }
        }

        $channels = [];
        if (! empty($filters['channels']) && is_array($filters['channels'])) {
            $channels = array_values(array_filter(array_map('strval', $filters['channels'])));
        } elseif (! empty($filters['channel']) && $filters['channel'] !== 'All') {
            $channels = [(string) $filters['channel']];
        }
        if ($channels !== []) {
            $query->where(function ($outer) use ($channels) {
                foreach ($channels as $channel) {
                    $outer->orWhere(function ($q) use ($channel) {
                        match ($channel) {
                            LeadChannelClassifier::WHATSAPP => $q->whereNotNull('leads.whatsapp_id')
                                ->orWhereRaw('LOWER(COALESCE(leads.source_code, \'\')) LIKE ?', ['%whatsapp%'])
                                ->orWhere('leads.source_code', 'whatsapp'),
                            LeadChannelClassifier::CALL => $q->whereRaw('LOWER(COALESCE(leads.connected_by, \'\')) LIKE ?', ['%call%'])
                                ->orWhereRaw('LOWER(COALESCE(leads.source_code, \'\')) LIKE ?', ['%call%']),
                            LeadChannelClassifier::MESSAGE => $q->whereRaw('LOWER(COALESCE(leads.source_code, \'\')) LIKE ?', ['%message%'])
                                ->orWhereRaw('LOWER(COALESCE(leads.source_code, \'\')) LIKE ?', ['%sms%'])
                                ->orWhereRaw('LOWER(COALESCE(leads.connected_by, \'\')) LIKE ?', ['%message%']),
                            LeadChannelClassifier::FORM => $q->whereNull('leads.whatsapp_id')
                                ->whereRaw('NOT (LOWER(COALESCE(leads.source_code, \'\')) LIKE ?)', ['%whatsapp%'])
                                ->where(function ($inner) {
                                    $inner->whereNull('leads.source_code')->orWhere('leads.source_code', '<>', 'whatsapp');
                                })
                                ->where(function ($inner) {
                                    $inner->whereNull('leads.connected_by')->orWhereRaw('LOWER(leads.connected_by) NOT LIKE ?', ['%call%']);
                                })
                                ->whereRaw('LOWER(COALESCE(leads.source_code, \'\')) NOT LIKE ?', ['%call%'])
                                ->whereRaw('LOWER(COALESCE(leads.source_code, \'\')) NOT LIKE ?', ['%message%'])
                                ->whereRaw('LOWER(COALESCE(leads.source_code, \'\')) NOT LIKE ?', ['%sms%']),
                            default => $q->whereRaw('1 = 1'),
                        };
                    });
                }
            });
        }

        $multiFields = [
            'countries' => 'country',
            'states' => 'state',
            'districts' => 'district',
            'courses' => 'course',
            'syllabuses' => 'syllabus',
            'classes' => 'class',
            'priorities' => 'priority',
            'campaigns' => 'campaign',
        ];
        foreach ($multiFields as $plural => $field) {
            if (! empty($filters[$plural]) && is_array($filters[$plural])) {
                $vals = array_values(array_filter(array_map('strval', $filters[$plural])));
                if ($vals === []) {
                    continue;
                }
                if ($field === 'course') {
                    $query->where(function ($q) use ($vals) {
                        $q->whereIn('leads.course', $vals)->orWhereIn('leads.course_interested', $vals);
                    });
                } else {
                    $query->whereIn('leads.'.$field, $vals);
                }
            } elseif (! empty($filters[$field]) && $filters[$field] !== 'All') {
                if ($field === 'course') {
                    $val = (string) $filters[$field];
                    $query->where(function ($q) use ($val) {
                        $q->where('leads.course', $val)->orWhere('leads.course_interested', $val);
                    });
                } else {
                    $query->where('leads.'.$field, $filters[$field]);
                }
            }
        }

        $brands = [];
        if (! empty($filters['sub_brands']) && is_array($filters['sub_brands'])) {
            $brands = array_values(array_filter(array_map('strval', $filters['sub_brands'])));
        } elseif (! empty($filters['sub_brand']) && $filters['sub_brand'] !== 'All') {
            $brands = [(string) $filters['sub_brand']];
        }
        if ($brands !== []) {
            $query->where(function ($q) use ($brands) {
                $q->whereExists(function ($sq) use ($brands) {
                    $sq->select(DB::raw(1))->from('users')
                        ->whereColumn('users.id', 'leads.owner_id')
                        ->whereIn('users.sub_brand', $brands);
                })->orWhereExists(function ($sq) use ($brands) {
                    $sq->select(DB::raw(1))->from('users')
                        ->whereColumn('users.id', 'leads.generated_by_user_id')
                        ->whereIn('users.sub_brand', $brands);
                });
            });
        }

        $mediums = [];
        if (! empty($filters['utm_mediums']) && is_array($filters['utm_mediums'])) {
            $mediums = array_values(array_filter(array_map('strval', $filters['utm_mediums'])));
        } elseif (! empty($filters['utm_medium']) && $filters['utm_medium'] !== 'All') {
            $mediums = [(string) $filters['utm_medium']];
        }
        if ($mediums !== []) {
            $query->where(function ($q) use ($mediums) {
                foreach ($mediums as $medium) {
                    $q->orWhere(function ($inner) use ($medium) {
                        $inner->where('leads.campaign', 'like', '%'.$medium.'%')
                            ->orWhere('leads.connected_by', 'like', '%'.$medium.'%');
                    });
                }
            });
        }

        $devices = [];
        if (! empty($filters['devices']) && is_array($filters['devices'])) {
            $devices = array_values(array_filter(array_map('strval', $filters['devices'])));
        } elseif (! empty($filters['device']) && $filters['device'] !== 'All') {
            $devices = [(string) $filters['device']];
        }
        if ($devices !== []) {
            $query->where(function ($q) use ($devices) {
                foreach ($devices as $device) {
                    $q->orWhere(function ($inner) use ($device) {
                        $inner->where('leads.connected_by', $device)
                            ->orWhere('leads.campaign', 'like', '%'.$device.'%');
                    });
                }
            });
        }

        $assessmentStatuses = [];
        if (! empty($filters['assessment_statuses']) && is_array($filters['assessment_statuses'])) {
            $assessmentStatuses = array_values(array_filter(array_map('strval', $filters['assessment_statuses'])));
        } elseif (! empty($filters['assessment_status']) && $filters['assessment_status'] !== 'All') {
            $assessmentStatuses = [(string) $filters['assessment_status']];
        }
        if ($assessmentStatuses !== []) {
            $query->where(function ($q) use ($assessmentStatuses) {
                $q->whereIn('leads.status', $assessmentStatuses)
                    ->orWhereExists(function ($sq) use ($assessmentStatuses) {
                        $sq->select(DB::raw(1))
                            ->from('lead_stages')
                            ->whereColumn('lead_stages.id', 'leads.stage_id')
                            ->whereIn('lead_stages.label', $assessmentStatuses);
                    });
            });
        }

        if (array_key_exists('unassigned', $filters) && $filters['unassigned'] !== null && $filters['unassigned'] !== '' && $filters['unassigned'] !== 'All') {
            $flag = filter_var($filters['unassigned'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($flag === true || $filters['unassigned'] === 'Unassigned' || $filters['unassigned'] === '1') {
                $query->whereNull('leads.owner_id');
            } elseif ($flag === false || $filters['unassigned'] === 'Assigned' || $filters['unassigned'] === '0') {
                $query->whereNotNull('leads.owner_id');
            }
        }

        if (! empty($filters['created_from'])) {
            $query->whereDate('leads.created_at', '>=', $filters['created_from']);
        }
        if (! empty($filters['created_to'])) {
            $query->whereDate('leads.created_at', '<=', $filters['created_to']);
        }
    }

    /**
     * List sales-pipeline leads matching a pivot cell (row dims + optional column dim).
     *
     * @param  array{
     *   rows?: list<string>,
     *   columns?: list<string>,
     *   row_values?: list<string>,
     *   column_value?: string|null,
     *   filters?: array<string, mixed>,
     *   page?: int,
     *   per_page?: int,
     *   q?: string
     * }  $payload
     */
    public function drillDownLeads(array $payload): LengthAwarePaginator
    {
        $rowKeys = array_values(array_filter(
            array_map('strval', $payload['rows'] ?? []),
            fn ($k) => $k !== ''
        ));
        $columnKeys = array_values(array_filter(
            array_map('strval', $payload['columns'] ?? []),
            fn ($k) => $k !== ''
        ));
        $rowValues = array_map(
            fn ($v) => $this->displayDim($v),
            array_values($payload['row_values'] ?? [])
        );

        if (count($rowKeys) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['rows' => 'At most '.self::MAX_ROWS.' row dimensions allowed.']);
        }
        if (count($columnKeys) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages(['columns' => 'At most '.self::MAX_COLUMNS.' column dimension allowed.']);
        }
        if (count($rowValues) !== count($rowKeys)) {
            throw ValidationException::withMessages(['row_values' => 'row_values must match rows length.']);
        }

        foreach ([...$rowKeys, ...$columnKeys] as $key) {
            if (! isset(self::DIMENSIONS[$key])) {
                throw ValidationException::withMessages(['dimensions' => "Unknown dimension: {$key}"]);
            }
        }

        $dimConstraints = [];
        foreach ($rowKeys as $i => $key) {
            $dimConstraints[$key] = $rowValues[$i];
        }

        $hasColumnConstraint = array_key_exists('column_value', $payload) && $payload['column_value'] !== null && $payload['column_value'] !== '';
        if ($columnKeys !== [] && $hasColumnConstraint) {
            $dimConstraints[$columnKeys[0]] = $this->displayDim($payload['column_value']);
        }

        $page = max(1, (int) ($payload['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($payload['per_page'] ?? 25)));
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

        $query = Lead::query()
            ->select('leads.*')
            ->with([
                'stage',
                'closedReason',
                'closedBy:id,first_name,last_name,email',
                'owner:id,first_name,last_name,email,role_id,department',
                'owner.role:id,key,name',
                'telecallerOwner:id,first_name,last_name,email,role_id',
                'psaOwner:id,first_name,last_name,email,role_id',
                'advisorOwner:id,first_name,last_name,email,role_id',
            ])
            ->whereNull('leads.deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('lead_stages')
                    ->whereColumn('lead_stages.id', 'leads.stage_id')
                    ->where('lead_stages.team', 'sales');
            });

        $joins = $this->collectJoins(array_keys($dimConstraints));
        $this->applyJoins($query->getQuery(), $joins);
        $this->applyFilters($query->getQuery(), $filters);

        foreach ($dimConstraints as $key => $value) {
            $expr = self::DIMENSIONS[$key]['expr'];
            $query->whereRaw("({$expr}) = ?", [$value]);
        }

        if (! empty($payload['q']) && is_string($payload['q'])) {
            $needle = trim($payload['q']);
            if ($needle !== '') {
                $query->where(function ($sq) use ($needle) {
                    $sq->where('leads.student_name', 'like', '%'.$needle.'%')
                        ->orWhere('leads.phone', 'like', '%'.$needle.'%')
                        ->orWhere('leads.email', 'like', '%'.$needle.'%');
                    if (preg_match('/^\d+$/', $needle)) {
                        $sq->orWhere('leads.id', (int) $needle);
                    }
                });
            }
        }

        return $query
            ->orderByDesc('leads.created_at')
            ->orderByDesc('leads.id')
            ->paginate($perPage, ['leads.*'], 'page', $page);
    }

    /**
     * @param  object|null  $row
     * @param  list<array{alias: string}>  $values
     * @return array<string, float>
     */
    private function extractMetrics(?object $row, array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[$value['alias']] = round((float) ($row?->{$value['alias']} ?? 0), 2);
        }

        return $out;
    }
}
