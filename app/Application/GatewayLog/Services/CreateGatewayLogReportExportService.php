<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ReportExport;

final readonly class CreateGatewayLogReportExportService
{
    public function create(
        ReportType $type,
        ?ReportFiltersData $filters = null,
    ): ReportExport {
        $filtersPayload = $filters?->toDatabaseArray() ?? [];

        logger()->info('Creating gateway log report export', [
            'type' => $type->value,
            'filters' => $filtersPayload,
        ]);

        return ReportExport::query()->create([
            'type' => $type,
            'status' => ReportExportStatus::Queued,
            'filters' => $filtersPayload === [] ? null : $filtersPayload,
        ]);
    }
}
