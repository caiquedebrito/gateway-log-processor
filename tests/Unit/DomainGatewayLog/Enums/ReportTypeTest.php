<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GatewayLog\Enums;

use App\Domain\GatewayLog\Enums\ReportType;
use PHPUnit\Framework\TestCase;

class ReportTypeTest extends TestCase
{
    public function test_it_has_expected_values(): void
    {
        $this->assertSame('requests_by_consumer', ReportType::RequestsByConsumer->value);
        $this->assertSame('requests_by_service', ReportType::RequestsByService->value);
        $this->assertSame('average_latency_by_service', ReportType::AverageLatencyByService->value);
    }

    public function test_it_returns_default_file_name(): void
    {
        $this->assertSame(
            'requests_by_consumer.csv',
            ReportType::RequestsByConsumer->defaultFileName()
        );

        $this->assertSame(
            'requests_by_service.csv',
            ReportType::RequestsByService->defaultFileName()
        );

        $this->assertSame(
            'average_latency_by_service.csv',
            ReportType::AverageLatencyByService->defaultFileName()
        );
    }

    public function test_it_returns_label(): void
    {
        $this->assertSame(
            'Requests by consumer',
            ReportType::RequestsByConsumer->label()
        );

        $this->assertSame(
            'Requests by service',
            ReportType::RequestsByService->label()
        );

        $this->assertSame(
            'Average latency by service',
            ReportType::AverageLatencyByService->label()
        );
    }
}
