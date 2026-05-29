<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Domain\GatewayLog\Enums\ReportType;
use App\Jobs\ExportGatewayLogReportJob;
use App\Models\ReportExport;

final readonly class QueueGatewayLogReportExportService
{
    public function __construct(
        private CreateGatewayLogReportExportService $createReportExportService,
    ) {}

    public function queue(ReportType $type): ReportExport
    {
        $export = $this->createReportExportService->create($type);

        ExportGatewayLogReportJob::dispatch(
            reportExportId: (int) $export->id,
        );

        return $export->refresh();
    }
}
