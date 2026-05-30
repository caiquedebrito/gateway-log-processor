<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Reports;

use App\Application\GatewayLog\Reports\Contracts\GatewayLogReportGenerator;
use App\Application\GatewayLog\Reports\Generators\AverageLatencyByServiceReportGenerator;
use App\Application\GatewayLog\Reports\Generators\RequestsByConsumerReportGenerator;
use App\Application\GatewayLog\Reports\Generators\RequestsByServiceReportGenerator;
use App\Application\GatewayLog\Reports\Support\GatewayLogReportQueryFilter;
use App\Domain\GatewayLog\Enums\ReportType;

final class GatewayLogReportFactory
{
    public function __construct(
        private GatewayLogReportQueryFilter $queryFilter = new GatewayLogReportQueryFilter,
    ) {}

    public function make(ReportType $type): GatewayLogReportGenerator
    {
        return match ($type) {
            ReportType::RequestsByConsumer => new RequestsByConsumerReportGenerator($this->queryFilter),
            ReportType::RequestsByService => new RequestsByServiceReportGenerator($this->queryFilter),
            ReportType::AverageLatencyByService => new AverageLatencyByServiceReportGenerator($this->queryFilter),
        };
    }
}
