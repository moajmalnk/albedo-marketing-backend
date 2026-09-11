<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Lead;
use App\Services\InvoiceNumberGenerator;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function show(Lead $lead)
    {
        $invoice = $lead->invoice;

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return response()->json($this->serialize($invoice));
    }

    public function store(Request $request, Lead $lead, InvoiceNumberGenerator $numbers)
    {
        if ($lead->invoice()->exists()) {
            return response()->json(['message' => 'Invoice already exists for this lead'], 409);
        }

        $data = $this->validatePayload($request);

        $invoice = DB::transaction(function () use ($request, $lead, $data, $numbers) {
            $invoiceDate = $data['invoice_date'] ?? now()->toDateString();

            $invoice = new Invoice([
                'lead_id' => $lead->id,
                'invoice_number' => $numbers->next(new \DateTimeImmutable($invoiceDate)),
                'invoice_date' => $invoiceDate,
                'student_name' => $data['student_name'] ?? ($lead->student_name ?? ''),
                'class_label' => $data['class_label'] ?? Invoice::defaultClassLabel($lead),
                'sessions' => $data['sessions'] ?? 0,
                'hours_per_session' => $data['hours_per_session'] ?? '1',
                'amount_per_session' => $data['amount_per_session'] ?? 0,
                'study_materials' => $data['study_materials'] ?? 0,
                'admission_fee' => $data['admission_fee'] ?? 0,
                'created_by' => $request->user()?->id,
            ]);

            $invoice->recalculateTotals();
            $invoice->save();

            return $invoice->fresh();
        });

        return response()->json($this->serialize($invoice), 201);
    }

    public function update(Request $request, Lead $lead)
    {
        $invoice = $lead->invoice;

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $data = $this->validatePayload($request);

        $invoice->fill([
            'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
            'student_name' => $data['student_name'] ?? $invoice->student_name,
            'class_label' => $data['class_label'] ?? $invoice->class_label,
            'sessions' => array_key_exists('sessions', $data) ? $data['sessions'] : $invoice->sessions,
            'hours_per_session' => $data['hours_per_session'] ?? $invoice->hours_per_session,
            'amount_per_session' => array_key_exists('amount_per_session', $data)
                ? $data['amount_per_session']
                : $invoice->amount_per_session,
            'study_materials' => array_key_exists('study_materials', $data)
                ? $data['study_materials']
                : $invoice->study_materials,
            'admission_fee' => array_key_exists('admission_fee', $data)
                ? $data['admission_fee']
                : $invoice->admission_fee,
        ]);

        $invoice->recalculateTotals();
        $invoice->save();

        return response()->json($this->serialize($invoice->fresh()));
    }

    public function pdf(Lead $lead, InvoicePdfService $pdfService)
    {
        $invoice = $lead->invoice;

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return $pdfService->download($invoice);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'invoice_date' => ['nullable', 'date'],
            'student_name' => ['nullable', 'string', 'max:255'],
            'class_label' => ['nullable', 'string', 'max:255'],
            'sessions' => ['nullable', 'integer', 'min:0'],
            'hours_per_session' => ['nullable', 'string', 'max:50'],
            'amount_per_session' => ['nullable', 'numeric', 'min:0'],
            'study_materials' => ['nullable', 'numeric', 'min:0'],
            'admission_fee' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function serialize(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'lead_id' => $invoice->lead_id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
            'student_name' => $invoice->student_name,
            'class_label' => $invoice->class_label,
            'sessions' => $invoice->sessions,
            'hours_per_session' => $invoice->hours_per_session,
            'amount_per_session' => (float) $invoice->amount_per_session,
            'study_materials' => (float) $invoice->study_materials,
            'tuition_fee' => (float) $invoice->tuition_fee,
            'admission_fee' => (float) $invoice->admission_fee,
            'total_fee' => (float) $invoice->total_fee,
            'package_amount' => $invoice->packageAmount(),
            'package_total' => $invoice->packageTotal(),
            'created_by' => $invoice->created_by,
            'created_at' => $invoice->created_at?->toIso8601String(),
            'updated_at' => $invoice->updated_at?->toIso8601String(),
        ];
    }
}
