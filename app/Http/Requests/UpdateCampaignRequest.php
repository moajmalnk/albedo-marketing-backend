<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $campaignId = $this->route('campaign') ? $this->route('campaign')->id : 'NULL';
        return [
            'name' => ['required', 'string', 'max:255', 'unique:campaigns,name,' . $campaignId],
            'platform' => ['required', 'string', 'max:255', 'exists:lead_sources,name'],
            'status' => ['required', 'string', 'in:draft,scheduled,active,paused,completed,archived'],
            'budget' => ['required', 'numeric', 'min:0'],
            'spend' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date', 'before_or_equal:end_date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'department' => ['nullable', 'string', 'max:32'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
