<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Reports\Contracts;

use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportType;

interface GatewayLogReportGenerator
{
    public function type(): ReportType;

    /**
     * @return list<string>
     */
    public function header(): array;

    /**
     * @return iterable<list<int|string|null>>
     */
    public function rows(ReportFiltersData $filters): iterable;
}
