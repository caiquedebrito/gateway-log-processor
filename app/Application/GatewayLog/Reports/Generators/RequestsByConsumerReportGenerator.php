<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Reports\Generators;

use App\Application\GatewayLog\Reports\Contracts\GatewayLogReportGenerator;
use App\Application\GatewayLog\Reports\Support\GatewayLogReportQueryFilter;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportType;
use Illuminate\Support\Facades\DB;

final class RequestsByConsumerReportGenerator implements GatewayLogReportGenerator
{
    public function __construct(
        private GatewayLogReportQueryFilter $queryFilter,
    ) {}

    public function type(): ReportType
    {
        return ReportType::RequestsByConsumer;
    }

    public function header(): array
    {
        return [
            'consumer_id',
            'total',
        ];
    }

    public function rows(ReportFiltersData $filters): iterable
    {
        $query = DB::table('api_gateway_logs');

        logger()->info('Generating RequestsByConsumer report with filters', [
            'filters' => $filters->toDatabaseArray(),
        ]);

        $this->queryFilter->apply($query, $filters);

        $rows = $query
            ->select([
                'consumer_id',
                DB::raw('COUNT(*) as total'),
            ])
            ->groupBy('consumer_id')
            ->orderByDesc('total')
            ->orderBy('consumer_id')
            ->cursor();

        foreach ($rows as $row) {
            yield [
                $row->consumer_id,
                (int) $row->total,
            ];
        }
    }
}
