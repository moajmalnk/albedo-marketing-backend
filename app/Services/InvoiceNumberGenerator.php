<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InvoiceNumberGenerator
{
    public function next(?\DateTimeInterface $date = null): string
    {
        $year = (int) ($date?->format('y') ?? now()->format('y'));
        $prefix = (string) config('invoice.number_prefix', 'AQ');

        return DB::transaction(function () use ($year, $prefix) {
            $row = DB::table('invoice_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('invoice_sequences')->insert([
                    'year' => $year,
                    'last_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('invoice_sequences')
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();
            }

            $next = ((int) $row->last_number) + 1;

            DB::table('invoice_sequences')
                ->where('year', $year)
                ->update([
                    'last_number' => $next,
                    'updated_at' => now(),
                ]);

            return sprintf('%s/%02d/%05d', $prefix, $year, $next);
        });
    }
}
