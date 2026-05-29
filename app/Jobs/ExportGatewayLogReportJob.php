<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\GatewayLog\Services\GenerateGatewayLogReportService;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Models\ReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ExportGatewayLogReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $reportExportId,
        public ?string $outputDirectory = null,
    ) {
        $this->onQueue('reports');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("gateway-log-report-{$this->reportExportId}"))
                ->expireAfter(300),
        ];
    }

    public function handle(GenerateGatewayLogReportService $service): void
    {
        $export = ReportExport::query()->findOrFail($this->reportExportId);

        if ($export->status instanceof ReportExportStatus && $export->status->isFinal()) {
            return;
        }

        $service->generate(
            export: $export,
            outputDirectory: $this->outputDirectory,
        );
    }

    public function failed(Throwable $exception): void
    {
        $export = ReportExport::query()->find($this->reportExportId);

        if ($export === null) {
            return;
        }

        if ($export->status instanceof ReportExportStatus && $export->status->isFinal()) {
            return;
        }

        $export->markAsFailed($exception->getMessage());
    }
}
