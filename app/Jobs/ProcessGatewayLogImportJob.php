<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\GatewayLog\Services\ProcessGatewayLogImportService;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Models\LogImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class ProcessGatewayLogImportJob implements ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $logImportId,
        public int $chunkSize = 1000,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("gateway-log-import-{$this->logImportId}"))
                ->expireAfter(300),
        ];
    }

    public function handle(ProcessGatewayLogImportService $service): void
    {
        $import = LogImport::query()->findOrFail($this->logImportId);

        if ($import->status instanceof LogImportStatus && $import->status->isFinal()) {
            return;
        }

        $progress = $service->processChunk(
            import: $import,
            chunkSize: $this->chunkSize,
        );

        if (! $progress->reachedEndOfFile) {
            self::dispatch(
                logImportId: $this->logImportId,
                chunkSize: $this->chunkSize,
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        $import = LogImport::query()->find($this->logImportId);

        if ($import === null) {
            return;
        }

        if ($import->status instanceof LogImportStatus && $import->status->isFinal()) {
            return;
        }

        $import->markAsFailed($exception->getMessage());
    }
}
