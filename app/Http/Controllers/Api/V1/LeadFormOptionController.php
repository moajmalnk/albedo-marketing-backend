<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadFormOption;
use App\Models\LeadFormOptionGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadFormOptionController extends Controller
{
    private function ensureSettingsAdmin(Request $request): void
    {
        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        if (! in_array($roleKey, ['super_admin', 'admin'], true)) {
            abort(403, 'You are not authorized to manage lead form options.');
        }
    }

    /**
     * Grouped picklists for the lead capture form.
     *
     * Default: **active options only** (for Add Lead and similar capture UIs).
     * When `include_inactive=1` is passed, returns all options per group (including inactive)
     * for Settings CRUD — requires the same role as mutating routes (`super_admin` / `admin`).
     *
     * @return \Illuminate\Http\JsonResponse<string, array<int, array{id:int,value:string,label:string,sort_order:int,is_active?:bool,meta?:array|null}>>
     */
    public function index(Request $request)
    {
        $includeInactive = $request->boolean('include_inactive');
        if ($includeInactive) {
            $this->ensureSettingsAdmin($request);
        }

        $request->user()?->loadMissing('role');

        $groups = LeadFormOptionGroup::query()
            ->with([
                'options' => fn ($q) => $q
                    ->when(! $includeInactive, fn ($q2) => $q2->where('is_active', true))
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->orderBy('slug')
            ->get();

        $out = [];
        foreach ($groups as $g) {
            $out[$g->slug] = $g->options->map(fn (LeadFormOption $o) => [
                'id' => $o->id,
                'value' => $o->value,
                'label' => $o->label,
                'sort_order' => $o->sort_order,
                'is_active' => (bool) $o->is_active,
                'meta' => $o->meta,
            ])->values()->all();
        }

        return response()->json($out);
    }

    public function store(Request $request)
    {
        $this->ensureSettingsAdmin($request);

        $data = $request->validate([
            'group_slug' => ['required', 'string', 'max:64', Rule::exists('lead_form_option_groups', 'slug')],
            'value' => ['required', 'string', 'max:191'],
            'label' => ['required', 'string', 'max:191'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'meta' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $group = LeadFormOptionGroup::query()->where('slug', $data['group_slug'])->firstOrFail();

        if (LeadFormOption::query()->where('group_id', $group->id)->where('value', $data['value'])->exists()) {
            return response()->json(['message' => 'Option value already exists in this group.'], 422);
        }

        $option = LeadFormOption::query()->create([
            'group_id' => $group->id,
            'value' => $data['value'],
            'label' => $data['label'],
            'sort_order' => $data['sort_order'] ?? 0,
            'meta' => $data['meta'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($option->load('group'), 201);
    }

    public function update(Request $request, LeadFormOption $lead_form_option)
    {
        $this->ensureSettingsAdmin($request);

        $data = $request->validate([
            'value' => ['sometimes', 'required', 'string', 'max:191', Rule::unique('lead_form_options', 'value')->ignore($lead_form_option->id)->where('group_id', $lead_form_option->group_id)],
            'label' => ['sometimes', 'required', 'string', 'max:191'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'meta' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $lead_form_option->update($data);

        return response()->json($lead_form_option->fresh()->load('group'));
    }

    public function destroy(Request $request, LeadFormOption $lead_form_option)
    {
        $this->ensureSettingsAdmin($request);
        $lead_form_option->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
