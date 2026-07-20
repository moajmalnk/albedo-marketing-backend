<?php

namespace App\Jobs;

use App\Models\LeadImport;
use App\Services\ImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected LeadImport $import;

    /** Relative path on the local disk where rows were staged. */
    protected string $rowsPath;

    protected ?string $ip;

    protected ?string $userAgent;

    public function __construct(LeadImport $import, string $rowsPath, ?string $ip = null, ?string $userAgent = null)
    {
        $this->import = $import;
        $this->rowsPath = $rowsPath;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    public function handle(ImportService $importService): void
    {
        if (! Storage::disk('local')->exists($this->rowsPath)) {
            throw new RuntimeException("Import rows file missing: {$this->rowsPath}");
        }

        $raw = Storage::disk('local')->get($this->rowsPath);
        $rows = json_decode($raw, true);

        if (! is_array($rows)) {
            throw new RuntimeException("Import rows file is invalid JSON: {$this->rowsPath}");
        }

        try {
            $importService->executeImport($this->import, $rows, $this->ip, $this->userAgent);
        } finally {
            Storage::disk('local')->delete($this->rowsPath);
            // Remove empty import folder when possible.
            $dir = dirname($this->rowsPath);
            if ($dir !== '.' && Storage::disk('local')->exists($dir)) {
                $remaining = Storage::disk('local')->files($dir);
                if ($remaining === []) {
                    Storage::disk('local')->deleteDirectory($dir);
                }
            }
        }
    }
}
