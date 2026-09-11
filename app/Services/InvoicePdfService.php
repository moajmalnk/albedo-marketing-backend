<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class InvoicePdfService
{
    public function download(Invoice $invoice): Response
    {
        $invoice->loadMissing('lead');

        $company = config('invoice.company', []);
        $payment = config('invoice.payment', []);
        $assets = config('invoice.assets', []);

        $logoPath = $this->usablePath($assets['logo'] ?? null);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => $company,
            'payment' => $payment,
            'logoPath' => $logoPath,
            'packageAmount' => $invoice->packageAmount(),
            'packageTotal' => $invoice->packageTotal(),
        ])->setPaper('a4');

        $fileName = str_replace('/', '-', $invoice->invoice_number).'.pdf';

        return $pdf->download($fileName);
    }

    private function usablePath(?string $path): ?string
    {
        if (! $path || ! is_file($path)) {
            return null;
        }

        return $path;
    }
}
