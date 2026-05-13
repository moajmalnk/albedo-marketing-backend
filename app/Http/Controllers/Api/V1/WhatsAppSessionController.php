<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class WhatsAppSessionController extends Controller
{
    private function ensureSuperAdminOrAdmin(Request $request): void
    {
        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        if (! in_array($roleKey, ['super_admin', 'admin'], true)) {
            abort(403, 'You are not authorized to manage WhatsApp sessions.');
        }
    }

    public function index(Request $request)
    {
        $this->ensureSuperAdminOrAdmin($request);

        $users = User::query()
            ->with(['role', 'defaultWhatsAppSession'])
            ->withCount(['whatsAppCapturedLeadsToday as leads_caught_today'])
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $users]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperAdminOrAdmin($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'session_name' => ['nullable', 'string', 'max:80'],
        ]);

        $name = $data['session_name'] ?? 'default';

        $session = WhatsAppSession::query()->updateOrCreate(
            ['user_id' => $data['user_id'], 'session_name' => $name],
            [
                'status' => 'PAIRING',
                'last_error' => null,
                'last_qr' => null,
            ]
        );

        return response()->json($session->fresh(), 201);
    }

    public function destroy(Request $request, WhatsAppSession $whatsappSession)
    {
        $this->ensureSuperAdminOrAdmin($request);

        $whatsappSession->update([
            'status' => 'DISCONNECTED',
            'last_qr' => null,
            'phone_number' => null,
        ]);

        return response()->json(['message' => 'ok']);
    }

    public function qr(Request $request, User $user)
    {
        $this->ensureSuperAdminOrAdmin($request);

        $base = (string) config('services.whatsapp_worker.internal_url', '');
        $token = (string) config('services.whatsapp_worker.token', '');

        if ($token === '' || $base === '') {
            return response()->json(['message' => 'WHATSAPP_WORKER_NOT_CONFIGURED'], 503);
        }

        $url = rtrim($base, '/').'/qr/'.$user->id;

        try {
            $res = Http::withHeaders(['X-Whatsapp-Worker-Token' => $token])
                ->timeout(15)
                ->get($url);

            if (! $res->successful()) {
                return response()->json([
                    'message' => 'WORKER_QR_FETCH_FAILED',
                    'detail' => $res->body(),
                ], 502);
            }

            return response()->json($res->json());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'WORKER_UNREACHABLE',
                'detail' => $e->getMessage(),
            ], 502);
        }
    }
}
