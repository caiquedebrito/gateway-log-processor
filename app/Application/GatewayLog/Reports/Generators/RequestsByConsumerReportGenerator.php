<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Reports\Generators;

use App\Application\GatewayLog\Reports\Contracts\GatewayLogReportGenerator;
use App\Domain\GatewayLog\Enums\ReportType;
use Illuminate\Support\Facades\DB;

final class RequestsByConsumerReportGenerator implements GatewayLogReportGenerator
{
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

    public function rows(): iterable
    {
        $rows = DB::table('api_gateway_logs')
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
