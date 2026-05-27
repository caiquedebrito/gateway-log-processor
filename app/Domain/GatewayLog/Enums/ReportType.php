<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\Enums;

enum ReportType: string
{
    case RequestsByConsumer = 'requests_by_consumer';
    case RequestsByService = 'requests_by_service';
    case AverageLatencyByService = 'average_latency_by_service';

    public function defaultFileName(): string
    {
        return "{$this->value}.csv";
    }

    public function label(): string
    {
        return match ($this) {
            self::RequestsByConsumer => 'Requests by consumer',
            self::RequestsByService => 'Requests by service',
            self::AverageLatencyByService => 'Average latency by service',
        };
    }
}
