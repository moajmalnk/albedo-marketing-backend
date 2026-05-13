<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    private const METHODS = ['cash', 'upi', 'card', 'bank_transfer', 'emi'];

    public function index(Request $request, ?Enrollment $enrollment = null)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'enrollment_id' => ['nullable', 'integer'],
            'method' => ['nullable', Rule::in(self::METHODS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Payment::query()
            ->with([
                'enrollment:id,lead_id,advisor_id',
                'enrollment.lead:id,student_name',
                'receiver:id,first_name,last_name',
            ])
            ->when($enrollment && $enrollment->exists, fn ($q) => $q->where('enrollment_id', $enrollment->id))
            ->when($request->filled('enrollment_id'), fn ($q) => $q->where('enrollment_id', (int) $request->input('enrollment_id')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->string('method')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('received_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('received_at', '<=', $request->date('to')))
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        $limit = (int) $request->input('limit', 50);
        $limit = max(1, min(200, $limit));

        return response()->json($query->paginate($limit));
    }

    public function store(Request $request, Enrollment $enrollment)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['required', Rule::in(self::METHODS)],
            'reference' => ['nullable', 'string', 'max:80'],
            'received_at' => ['required', 'date'],
            'received_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $data['enrollment_id'] = $enrollment->id;
        $data['received_by'] = $data['received_by'] ?? $request->user()?->id;

        $payment = DB::transaction(function () use ($enrollment, $data) {
            $payment = Payment::query()->create($data);

            $totalPaid = (float) $enrollment->payments()->sum('amount');
            $balance = max(0, (float) $enrollment->package_amount - $totalPaid);
            $enrollment->update([
                'spot_amount' => $totalPaid,
                'balance_amount' => $balance,
                'admission_status' => $balance <= 0 ? 'full' : ($enrollment->admission_status === 'full' ? 'partial' : $enrollment->admission_status),
            ]);

            return $payment;
        });

        return response()->json(
            $payment->load(['receiver:id,first_name,last_name']),
            201
        );
    }

    public function update(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', Rule::in(self::METHODS)],
            'reference' => ['nullable', 'string', 'max:80'],
            'received_at' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($payment, $data) {
            $payment->update($data);
            $enrollment = $payment->enrollment()->first();
            if ($enrollment) {
                $totalPaid = (float) $enrollment->payments()->sum('amount');
                $balance = max(0, (float) $enrollment->package_amount - $totalPaid);
                $enrollment->update([
                    'spot_amount' => $totalPaid,
                    'balance_amount' => $balance,
                    'admission_status' => $balance <= 0 ? 'full' : ($enrollment->admission_status === 'full' ? 'partial' : $enrollment->admission_status),
                ]);
            }
        });

        return response()->json($payment->fresh()->load(['receiver:id,first_name,last_name']));
    }

    public function destroy(Payment $payment)
    {
        DB::transaction(function () use ($payment) {
            $enrollment = $payment->enrollment()->first();
            $payment->delete();
            if ($enrollment) {
                $totalPaid = (float) $enrollment->payments()->sum('amount');
                $balance = max(0, (float) $enrollment->package_amount - $totalPaid);
                $enrollment->update([
                    'spot_amount' => $totalPaid,
                    'balance_amount' => $balance,
                ]);
            }
        });

        return response()->json(['message' => 'Payment deleted']);
    }
}
