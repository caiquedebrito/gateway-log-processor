<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Reports\Generators;

use App\Application\GatewayLog\Reports\Contracts\GatewayLogReportGenerator;
use App\Application\GatewayLog\Reports\Support\GatewayLogReportQueryFilter;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportType;
use Illuminate\Support\Facades\DB;

final class AverageLatencyByServiceReportGenerator implements GatewayLogReportGenerator
{
    public function __construct(
        private GatewayLogReportQueryFilter $queryFilter,
    ) {}

    public function type(): ReportType
    {
        return ReportType::AverageLatencyByService;
    }

    public function header(): array
    {
        return [
            'service_name',
            'avg_latency_request',
            'avg_latency_proxy',
            'avg_latency_gateway',
        ];
    }

    public function rows(ReportFiltersData $filters): iterable
    {
        $query = DB::table('api_gateway_logs');

        $this->queryFilter->apply($query, $filters);

        $rows = $query
            ->select([
                'service_name',
                DB::raw('AVG(latency_request) as avg_latency_request'),
                DB::raw('AVG(latency_proxy) as avg_latency_proxy'),
                DB::raw('AVG(latency_gateway) as avg_latency_gateway'),
            ])
            ->groupBy('service_name')
            ->orderBy('service_name')
            ->cursor();

        foreach ($rows as $row) {
            yield [
                $row->service_name,
                number_format((float) $row->avg_latency_request, 2, '.', ''),
                number_format((float) $row->avg_latency_proxy, 2, '.', ''),
                number_format((float) $row->avg_latency_gateway, 2, '.', ''),
            ];
        }
    }
}
