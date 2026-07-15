<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Lead;
use App\Models\LeadStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    private const ADMISSION_STATUSES = ['DP', 'partial', 'full'];
    private const ENROLLMENT_TYPES = ['new_admission', 'repackage'];
    private const PAYMENT_METHODS = ['cash', 'upi', 'card', 'bank_transfer', 'emi'];

    public function index(Request $request)
    {
        $this->authorizeUser($request, 'index');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'advisor_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'admission_status' => ['nullable', Rule::in(self::ADMISSION_STATUSES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        $query = Enrollment::query()
            ->with([
                'lead:id,student_name,phone',
                'advisor:id,first_name,last_name',
                'payments',
            ])
            ->when($roleKey === 'advisor', fn ($q) => $q->where('advisor_id', $actor->id))
            ->when($request->filled('advisor_id') && $roleKey !== 'advisor', fn ($q) => $q->where('advisor_id', (int) $request->input('advisor_id')))
            ->when($request->filled('lead_id'), fn ($q) => $q->where('lead_id', (int) $request->input('lead_id')))
            ->when($request->filled('admission_status'), fn ($q) => $q->where('admission_status', $request->string('admission_status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $limit = (int) $request->input('limit', 50);
        $limit = max(1, min(200, $limit));

        return response()->json($query->paginate($limit));
    }

    public function show(Request $request, Enrollment $enrollment)
    {
        $this->authorizeUser($request, 'show', $enrollment);

        return response()->json(
            $enrollment->load([
                'lead:id,student_name,phone',
                'advisor:id,first_name,last_name',
                'payments',
            ])
        );
    }

    public function store(Request $request)
    {
        $this->authorizeUser($request, 'store');

        $data = $this->validatePayload($request, true);

        $enrollment = DB::transaction(function () use ($request, $data) {
            $enrollment = Enrollment::query()->create($data);
            $enrollment->ensureOpeningPayment($request->user()?->id);
            $enrollment->syncAmountsFromPayments();

            return $enrollment->fresh();
        });

        return response()->json(
            $enrollment->load([
                'lead:id,student_name,phone',
                'advisor:id,first_name,last_name',
                'payments',
            ]),
            201
        );
    }

    public function update(Request $request, Enrollment $enrollment)
    {
        $this->authorizeUser($request, 'update', $enrollment);

        $data = $this->validatePayload($request, false);

        $enrollment = DB::transaction(function () use ($request, $enrollment, $data) {
            $enrollment->update($data);
            $enrollment->refresh();

            // Manual spot with no ledger yet → open as a payment.
            // If payments already exist, paid/balance always follow the ledger.
            if ($enrollment->payments()->doesntExist()) {
                $enrollment->ensureOpeningPayment($request->user()?->id);
            }
            if ($enrollment->payments()->exists()) {
                $enrollment->syncAmountsFromPayments();
            }

            return $enrollment->fresh();
        });

        return response()->json(
            $enrollment->load([
                'lead:id,student_name,phone',
                'advisor:id,first_name,last_name',
                'payments',
            ])
        );
    }

    public function destroy(Request $request, Enrollment $enrollment)
    {
        $this->authorizeUser($request, 'destroy', $enrollment);

        $enrollment->delete();

        return response()->json(['message' => 'Enrollment deleted']);
    }

    private function authorizeUser(Request $request, string $action, ?Enrollment $enrollment = null): void
    {
        $actor = $request->user()?->loadMissing('role');
        $roleKey = $actor?->role?->key;

        if ($action === 'index') {
            if (! in_array($roleKey, ['super_admin', 'admin', 'sales_head', 'advisor'], true)) {
                abort(403, 'You are not authorized to view enrollments.');
            }
        } elseif ($action === 'store') {
            if (! in_array($roleKey, ['super_admin', 'admin', 'sales_head', 'advisor', 'telecaller'], true)) {
                abort(403, 'You are not authorized to create enrollments.');
            }
        } elseif ($action === 'show' || $action === 'update') {
            if (! in_array($roleKey, ['super_admin', 'admin', 'sales_head', 'advisor'], true)) {
                abort(403, "You are not authorized to {$action} this enrollment.");
            }
            if ($roleKey === 'advisor' && $enrollment && (int) $enrollment->advisor_id !== (int) $actor->id) {
                abort(403, "You are not authorized to {$action} this enrollment.");
            }
        } elseif ($action === 'destroy') {
            if (! in_array($roleKey, ['super_admin', 'admin', 'sales_head'], true)) {
                abort(403, 'You are not authorized to delete enrollments.');
            }
        }
    }

    private function validatePayload(Request $request, bool $isCreate): array
    {
        $rules = [
            'lead_id' => [$isCreate ? 'required' : 'sometimes', 'integer', 'exists:leads,id'],
            'advisor_id' => [$isCreate ? 'required' : 'sometimes', 'integer', 'exists:users,id'],
            'enrollment_type' => [$isCreate ? 'required' : 'sometimes', Rule::in(self::ENROLLMENT_TYPES)],
            'admission_status' => [$isCreate ? 'required' : 'sometimes', Rule::in(self::ADMISSION_STATUSES)],
            'package_amount' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'spot_amount' => ['nullable', 'numeric', 'min:0'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'balance_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(self::PAYMENT_METHODS)],
            'course_start_date' => ['nullable', 'date'],
            'course_end_date' => ['nullable', 'date', 'after_or_equal:course_start_date'],
            'confirmed_at' => ['nullable', 'date'],
        ];

        $data = $request->validate($rules);

        if (isset($data['package_amount'], $data['spot_amount']) && ! isset($data['balance_amount'])) {
            $data['balance_amount'] = max(0, (float) $data['package_amount'] - (float) $data['spot_amount']);
        }

        return $data;
    }
}
