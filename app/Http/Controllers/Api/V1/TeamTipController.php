<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeamTip;
use App\Models\TeamTipRead;
use App\Models\TeamTipBookmark;
use App\Models\TeamTipCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class TeamTipController extends Controller
{
    private function ensureCanManageTips(Request $request): void
    {
        $user = $request->user()?->loadMissing('role');
        $key = $user?->role?->key;

        if (! in_array($key, ['super_admin', 'admin', 'dept_head', 'department_head'], true)) {
            abort(403, 'You are not authorized to manage team tips.');
        }
    }

    private function senderDisplayName(User $user): string
    {
        $n = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $n !== '' ? $n : ($user->email ?? 'Unknown');
    }

    private function senderRoleLabel(User $user): ?string
    {
        return $user->role?->name;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeTipsForUser(Collection|\Illuminate\Pagination\AbstractPaginator $tips, User $user): array
    {
        $tipCollection = $tips instanceof \Illuminate\Pagination\AbstractPaginator ? $tips->getCollection() : $tips;
        
        $reads = TeamTipRead::query()
            ->where('user_id', $user->id)
            ->whereIn('team_tip_id', $tipCollection->pluck('id'))
            ->get()
            ->keyBy('team_tip_id');
            
        $bookmarks = TeamTipBookmark::query()
            ->where('user_id', $user->id)
            ->whereIn('team_tip_id', $tipCollection->pluck('id'))
            ->pluck('team_tip_id')
            ->flip();

        $serialized = $tipCollection->map(function (TeamTip $tip) use ($reads, $bookmarks) {
            $read = $reads->get($tip->id);
            $payload = $tip->toArray();
            if ($tip->relationLoaded('category')) {
                $payload['category'] = $tip->category;
            }
            if ($tip->relationLoaded('creator')) {
                $payload['creator'] = $tip->creator;
            }
            $payload['is_read'] = $read !== null;
            $payload['read_at'] = $read?->read_at?->toIso8601String();
            $payload['first_read_at'] = $read?->first_read_at?->toIso8601String();
            $payload['user_read_count'] = $read?->read_count ?? 0;
            $payload['is_bookmarked'] = $bookmarks->has($tip->id);

            return $payload;
        })->values()->all();
        
        return $serialized;
    }

    private function isVisibleToUser(TeamTip $tip, User $user): bool
    {
        if ($tip->status !== 'Active') {
            return false;
        }

        $targets = collect($tip->sent_to ?? [])
            ->filter(fn ($target) => is_string($target) && trim($target) !== '')
            ->map(fn ($target) => strtolower(trim((string) $target)))
            ->values();

        if ($targets->isEmpty()) {
            return false;
        }

        $roleKey = $user->role?->key;
        $name = strtolower(trim(($user->first_name ?? '').' '.($user->last_name ?? '')));
        $department = strtolower((string) $user->department);

        $aliases = collect([
            (string) $user->id,
            strtolower($user->email ?? ''),
            $name,
            $department,
            $department ? 'all '.$department : null,
            $department === 'pm' ? 'performance marketing' : null,
            $department === 'pm' ? 'all pm' : null,
            $department === 'pm' ? 'all performance marketing' : null,
            $department === 'im' ? 'influence marketing' : null,
            $department === 'im' ? 'all im' : null,
            $department === 'im' ? 'all influence marketing' : null,
            $user->sub_brand ? strtolower((string) $user->sub_brand) : null,
            $user->sub_brand ? 'all '.strtolower((string) $user->sub_brand) : null,
        ])->filter()->values();

        if ($targets->contains(fn ($target) => $aliases->contains($target))) {
            return true;
        }

        if ($targets->contains(fn ($target) => in_array($target, ['all users', 'all staff'], true))) {
            return true;
        }

        if ($roleKey === 'telecaller' && $targets->contains('all telecallers')) {
            return true;
        }

        if ($roleKey === 'marketer' && $targets->contains(fn ($target) => in_array($target, ['marketers', 'all marketers'], true))) {
            return true;
        }

        return false;
    }
    
    public function categories(Request $request)
    {
        return response()->json(TeamTipCategory::orderBy('name')->get());
    }
    
    public function stats(Request $request)
    {
        $user = $request->user()?->loadMissing('role');
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Fetch all active tips visible to user
        $tips = TeamTip::query()
            ->where('status', 'Active')
            ->get()
            ->filter(fn (TeamTip $tip) => $this->isVisibleToUser($tip, $user))
            ->values();
            
        $tipIds = $tips->pluck('id');
        
        $reads = TeamTipRead::query()
            ->where('user_id', $user->id)
            ->whereIn('team_tip_id', $tipIds)
            ->pluck('team_tip_id')
            ->flip();
            
        $stats = [
            'total' => $tips->count(),
            'unread' => $tips->filter(fn($tip) => !$reads->has($tip->id))->count(),
            'high_priority' => $tips->filter(fn($tip) => in_array($tip->priority, ['High', 'Critical']))->count(),
            'pinned' => $tips->filter(fn($tip) => $tip->pinned)->count(),
            'recently_updated' => $tips->filter(fn($tip) => $tip->updated_at && $tip->updated_at->diffInDays(now()) <= 7)->count(),
            'my_department' => $tips->filter(fn($tip) => strtolower((string)$tip->department) === strtolower((string)$user->department))->count(),
        ];
        
        return response()->json($stats);
    }

    public function mine(Request $request)
    {
        $user = $request->user()?->loadMissing('role');
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = TeamTip::with(['category', 'creator'])
            ->where('status', 'Active')
            ->orderByDesc('pinned')
            ->orderByDesc('date_sent')
            ->orderByDesc('id');
            
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }
        
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }
        
        if ($request->filled('department')) {
            $query->where('department', $request->query('department'));
        }
        
        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }
        
        if ($request->filled('pinned')) {
            $query->where('pinned', filter_var($request->query('pinned'), FILTER_VALIDATE_BOOLEAN));
        }

        $tips = $query->get()
            ->filter(fn (TeamTip $tip) => $this->isVisibleToUser($tip, $user))
            ->values();
            
        // Filter by unread or bookmarked if requested
        $serialized = $this->serializeTipsForUser($tips, $user);
        $collection = collect($serialized);
        
        if ($request->filled('unread') && filter_var($request->query('unread'), FILTER_VALIDATE_BOOLEAN)) {
            $collection = $collection->where('is_read', false);
        }
        
        if ($request->filled('bookmarked') && filter_var($request->query('bookmarked'), FILTER_VALIDATE_BOOLEAN)) {
            $collection = $collection->where('is_bookmarked', true);
        }

        return response()->json($collection->values()->all());
    }

    public function markRead(Request $request, TeamTip $team_tip)
    {
        $user = $request->user()?->loadMissing('role');
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (! $this->isVisibleToUser($team_tip, $user)) {
            abort(404);
        }

        DB::transaction(function () use ($team_tip, $user): void {
            $read = TeamTipRead::query()->firstOrCreate(
                ['team_tip_id' => $team_tip->id, 'user_id' => $user->id],
                ['first_read_at' => now(), 'read_at' => now(), 'read_count' => 0]
            );
            
            $read->read_at = now();
            $read->increment('read_count');
            
            if ($read->wasRecentlyCreated || $read->read_count === 1) {
                $team_tip->increment('read_count');
            }
        });

        return response()->json($this->serializeTipsForUser(collect([$team_tip->fresh(['category', 'creator'])]), $user)[0]);
    }
    
    public function bookmark(Request $request, TeamTip $team_tip)
    {
        $user = $request->user()?->loadMissing('role');
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (! $this->isVisibleToUser($team_tip, $user)) {
            abort(404);
        }
        
        TeamTipBookmark::firstOrCreate([
            'team_tip_id' => $team_tip->id,
            'user_id' => $user->id,
        ]);
        
        return response()->json(['message' => 'Bookmarked successfully']);
    }
    
    public function unbookmark(Request $request, TeamTip $team_tip)
    {
        $user = $request->user()?->loadMissing('role');
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        
        TeamTipBookmark::where('team_tip_id', $team_tip->id)
            ->where('user_id', $user->id)
            ->delete();
            
        return response()->json(['message' => 'Bookmark removed']);
    }

    public function markNormalRead(Request $request)
    {
        $user = $request->user()?->loadMissing('role');
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $tips = TeamTip::query()
            ->where('status', 'Active')
            ->where(function ($query) {
                $query->whereNull('priority')->orWhereNotIn('priority', ['High', 'Critical']);
            })
            ->get()
            ->filter(fn (TeamTip $tip) => $this->isVisibleToUser($tip, $user))
            ->values();

        $createdCount = 0;
        DB::transaction(function () use ($tips, $user, &$createdCount): void {
            foreach ($tips as $tip) {
                $read = TeamTipRead::query()->firstOrCreate(
                    ['team_tip_id' => $tip->id, 'user_id' => $user->id],
                    ['first_read_at' => now(), 'read_at' => now(), 'read_count' => 0]
                );
                
                if ($read->wasRecentlyCreated) {
                    $createdCount++;
                    $tip->increment('read_count');
                }
                
                $read->read_at = now();
                $read->increment('read_count');
            }
        });

        return response()->json(['marked_read' => $createdCount]);
    }

    public function index(Request $request)
    {
        $this->ensureCanManageTips($request);

        $query = TeamTip::with(['category', 'creator'])->orderByDesc('date_sent')->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('date_sent', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('date_sent', '<=', $request->query('to'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->ensureCanManageTips($request);

        $user = $request->user()->loadMissing('role');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string', 'max:20000'],
            'content' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:team_tip_categories,id'],
            'department' => ['nullable', 'string', 'max:191'],
            'pinned' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array'],
            'sent_to' => ['required', 'array', 'min:1', 'max:200'],
            'sent_to.*' => ['string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['Active', 'Inactive'])],
            'priority' => ['nullable', 'string', Rule::in(['Low', 'Normal', 'Medium', 'High', 'Critical'])],
            'read_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'date_sent' => ['nullable', 'date'],
        ]);

        $row = TeamTip::query()->create([
            'title' => $data['title'],
            'description' => $data['description'],
            'content' => $data['content'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'department' => $data['department'] ?? null,
            'pinned' => $data['pinned'] ?? false,
            'attachments' => $data['attachments'] ?? null,
            'sent_to' => $data['sent_to'],
            'sent_by' => $this->senderDisplayName($user),
            'sent_by_role' => $this->senderRoleLabel($user),
            'date_sent' => $data['date_sent'] ?? now()->toDateString(),
            'status' => $data['status'] ?? 'Active',
            'priority' => $data['priority'] ?? null,
            'read_count' => $data['read_count'] ?? 0,
            'created_by' => $user->id,
        ]);

        return response()->json($row->fresh(['category', 'creator']), 201);
    }

    public function show(Request $request, TeamTip $team_tip)
    {
        $this->ensureCanManageTips($request);

        return response()->json($team_tip->loadMissing(['category', 'creator']));
    }

    public function update(Request $request, TeamTip $team_tip)
    {
        $this->ensureCanManageTips($request);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['sometimes', 'required', 'string', 'max:20000'],
            'content' => ['sometimes', 'nullable', 'string'],
            'category_id' => ['sometimes', 'nullable', 'exists:team_tip_categories,id'],
            'department' => ['sometimes', 'nullable', 'string', 'max:191'],
            'pinned' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'nullable', 'array'],
            'sent_to' => ['sometimes', 'required', 'array', 'min:1', 'max:200'],
            'sent_to.*' => ['string', 'max:120'],
            'status' => ['sometimes', 'string', Rule::in(['Active', 'Inactive'])],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(['Low', 'Normal', 'Medium', 'High', 'Critical'])],
            'read_count' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'date_sent' => ['sometimes', 'date'],
        ]);

        $team_tip->update($data);

        return response()->json($team_tip->fresh(['category', 'creator']));
    }

    public function destroy(Request $request, TeamTip $team_tip)
    {
        $this->ensureCanManageTips($request);

        $team_tip->delete();

        return response()->json(['message' => 'Tip deleted']);
    }
}
