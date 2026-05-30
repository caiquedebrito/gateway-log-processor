<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Reports\Generators;

use App\Application\GatewayLog\Reports\Contracts\GatewayLogReportGenerator;
use App\Application\GatewayLog\Reports\Support\GatewayLogReportQueryFilter;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportType;
use Illuminate\Support\Facades\DB;

final class RequestsByServiceReportGenerator implements GatewayLogReportGenerator
{
    public function __construct(
        private GatewayLogReportQueryFilter $queryFilter,
    ) {}

    public function type(): ReportType
    {
        return ReportType::RequestsByService;
    }

    public function header(): array
    {
        return [
            'service_name',
            'total',
        ];
    }

    public function rows(ReportFiltersData $filters): iterable
    {
        $query = DB::table('api_gateway_logs');

        $this->queryFilter->apply($query, $filters);

        $rows = $query
            ->select([
                'service_name',
                DB::raw('COUNT(*) as total'),
            ])
            ->groupBy('service_name')
            ->orderByDesc('total')
            ->orderBy('service_name')
            ->cursor();

        foreach ($rows as $row) {
            yield [
                $row->service_name,
                (int) $row->total,
            ];
        }
    }
}
