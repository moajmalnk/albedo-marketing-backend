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
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'advisor_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'admission_status' => ['nullable', Rule::in(self::ADMISSION_STATUSES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Enrollment::query()
            ->with([
                'lead:id,student_name,phone',
                'advisor:id,first_name,last_name',
                'payments',
            ])
            ->when($request->filled('advisor_id'), fn ($q) => $q->where('advisor_id', (int) $request->input('advisor_id')))
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

    public function show(Enrollment $enrollment)
    {
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
        $data = $this->validatePayload($request, true);

        $enrollment = DB::transaction(function () use ($data) {
            $enrollment = Enrollment::query()->create($data);

            if (($data['admission_status'] ?? null) === 'full') {
                $enrolledStageId = LeadStage::query()->where('key', 'enrolled')->value('id');
                if ($enrolledStageId) {
                    Lead::query()->whereKey($data['lead_id'])->update(['stage_id' => $enrolledStageId]);
                }
            }

            return $enrollment;
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
        $data = $this->validatePayload($request, false);

        DB::transaction(function () use ($enrollment, $data) {
            $enrollment->update($data);

            if (($data['admission_status'] ?? null) === 'full') {
                $enrolledStageId = LeadStage::query()->where('key', 'enrolled')->value('id');
                if ($enrolledStageId) {
                    Lead::query()->whereKey($enrollment->lead_id)->update(['stage_id' => $enrolledStageId]);
                }
            }
        });

        return response()->json(
            $enrollment->fresh()->load([
                'lead:id,student_name,phone',
                'advisor:id,first_name,last_name',
                'payments',
            ])
        );
    }

    public function destroy(Enrollment $enrollment)
    {
        $enrollment->delete();

        return response()->json(['message' => 'Enrollment deleted']);
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
