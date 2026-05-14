<?php

namespace Database\Seeders;

use App\Models\LeadFormOption;
use App\Models\LeadFormOptionGroup;
use Illuminate\Database\Seeder;

class LeadFormOptionSeeder extends Seeder
{
    public function run(): void
    {
        $defs = [
            'connected_by' => [
                'label' => 'Connected By',
                'options' => [
                    ['INBOUND_CALL', 'Inbound Call', 10],
                    ['INBOUND_WHATSAPP', 'Inbound WhatsApp', 20],
                    ['OUTBOUND_CALL', 'Outbound Call', 30],
                    ['OUTBOUND_WHATSAPP', 'Outbound WhatsApp', 40],
                    ['WEBSITE_ENQUIRY', 'Website Enquiry', 50],
                ],
            ],
            'source_name' => [
                'label' => 'Source Name',
                'options' => [
                    ['influence', 'Influence Marketing', 10, ['source_group' => 'influence']],
                    ['performance', 'Performance Marketing', 20, ['source_group' => 'performance']],
                    ['customer_referral', 'Customer Referral', 30, ['source_group' => 'reference']],
                    ['employee_referral', 'Employee Referral', 40, ['source_group' => 'reference']],
                    ['reference', 'Reference', 50, ['source_group' => 'reference']],
                    ['albedo', 'Albedo', 60, ['source_group' => 'albedo']],
                    ['other', 'Other', 70, ['source_group' => 'other']],
                ],
            ],
            'source_code' => [
                'label' => 'Source Code',
                'options' => [
                    ['NSF_014', 'NSF 014', 10],
                    ['YT_003', 'YT 003', 20],
                    ['WEB_ORG', 'Website Organic', 30],
                    ['STU_REF', 'Student Referral', 40],
                ],
            ],
            'children_count' => [
                'label' => 'Number of Children',
                'options' => collect(range(1, 10))->map(fn ($n) => [(string) $n, str_pad((string) $n, 2, '0', STR_PAD_LEFT), $n * 10])->all(),
            ],
            'yes_no_enrolled' => [
                'label' => 'Already Enrolled',
                'options' => [
                    ['yes', 'Yes', 10],
                    ['no', 'No', 20],
                ],
            ],
            'country' => [
                'label' => 'Country',
                'options' => [
                    ['India', 'India', 10],
                    ['UAE', 'United Arab Emirates', 20],
                    ['Saudi Arabia', 'Saudi Arabia', 30],
                    ['Oman', 'Oman', 40],
                    ['United States', 'United States / Canada', 50],
                ],
            ],
            'state' => [
                'label' => 'State',
                'options' => [
                    ['Kerala', 'Kerala', 10],
                    ['Karnataka', 'Karnataka', 20],
                    ['Tamil Nadu', 'Tamil Nadu', 30],
                    ['Maharashtra', 'Maharashtra', 40],
                ],
            ],
            'city' => [
                'label' => 'City',
                'options' => [
                    ['Kochi', 'Kochi', 10],
                    ['Kozhikode', 'Kozhikode', 20],
                    ['Thrissur', 'Thrissur', 30],
                    ['Bengaluru', 'Bengaluru', 40],
                ],
            ],
        ];

        foreach ($defs as $slug => $cfg) {
            $group = LeadFormOptionGroup::query()->firstOrCreate(
                ['slug' => $slug],
                ['label' => $cfg['label']]
            );
            foreach ($cfg['options'] as $row) {
                $meta = isset($row[3]) && is_array($row[3]) ? $row[3] : null;
                LeadFormOption::query()->updateOrCreate(
                    ['group_id' => $group->id, 'value' => $row[0]],
                    [
                        'label' => $row[1],
                        'sort_order' => $row[2],
                        'is_active' => true,
                        'meta' => $meta,
                    ]
                );
            }
        }
    }
}
