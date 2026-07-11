<?php

namespace App\Jobs;

use App\Models\LeadImport;
use App\Services\ImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $import;
    protected $rows;
    protected $ip;
    protected $userAgent;

    public function __construct(LeadImport $import, array $rows, ?string $ip = null, ?string $userAgent = null)
    {
        $this->import = $import;
        $this->rows = $rows;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    public function handle(ImportService $importService): void
    {
        $importService->executeImport($this->import, $this->rows, $this->ip, $this->userAgent);
    }
}
