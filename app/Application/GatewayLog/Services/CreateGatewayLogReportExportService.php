<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ReportExport;

final readonly class CreateGatewayLogReportExportService
{
    public function create(ReportType $type): ReportExport
    {
        return ReportExport::query()->create([
            'type' => $type,
            'status' => ReportExportStatus::Queued,
        ]);
    }
}
