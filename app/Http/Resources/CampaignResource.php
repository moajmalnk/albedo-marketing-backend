<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'status' => $this->status,
            'budget' => (float) $this->budget,
            'spend' => (float) $this->spend,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'department' => $this->department,
            'owner' => $this->relationLoaded('owner') && $this->owner ? [
                'id' => $this->owner->id,
                'first_name' => $this->owner->first_name,
                'last_name' => $this->owner->last_name,
            ] : null,
            'created_by' => $this->relationLoaded('creator') && $this->creator ? [
                'id' => $this->creator->id,
                'first_name' => $this->creator->first_name,
                'last_name' => $this->creator->last_name,
            ] : null,
            'leads_count' => $this->leads_count ?? 0,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
