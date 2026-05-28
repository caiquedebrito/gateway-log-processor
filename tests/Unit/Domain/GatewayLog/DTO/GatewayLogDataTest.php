<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GatewayLog\DTO;

use App\Domain\GatewayLog\DTO\GatewayLogData;
use App\Domain\GatewayLog\DTO\LatencyData;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GatewayLogDataTest extends TestCase
{
    public function test_it_can_be_created(): void
    {
        $startedAt = CarbonImmutable::parse('2015-06-02 04:30:22.425');

        $data = new GatewayLogData(
            lineNumber: 1,
            byteOffset: 0,
            consumerId: '80f74eef-31b8-45d5-c525-ae532297ea8e',
            serviceId: '0590139e-7481-466c-bcdf-929adcaaf804',
            serviceName: 'myservice',
            requestMethod: 'GET',
            requestUri: '/get',
            responseStatus: 200,
            latencies: new LatencyData(
                request: 1921,
                proxy: 1430,
                gateway: 9,
            ),
            startedAt: $startedAt,
            createdAt: $startedAt,
            rawPayload: [
                'service' => [
                    'name' => 'myservice',
                ],
            ],
        );

        $this->assertSame(1, $data->lineNumber);
        $this->assertSame(0, $data->byteOffset);
        $this->assertSame('80f74eef-31b8-45d5-c525-ae532297ea8e', $data->consumerId);
        $this->assertSame('0590139e-7481-466c-bcdf-929adcaaf804', $data->serviceId);
        $this->assertSame('myservice', $data->serviceName);
        $this->assertSame('GET', $data->requestMethod);
        $this->assertSame('/get', $data->requestUri);
        $this->assertSame(200, $data->responseStatus);
        $this->assertSame(1921, $data->latencies->request);
        $this->assertTrue($startedAt->equalTo($data->startedAt));
        $this->assertTrue($startedAt->equalTo($data->createdAt));
        $this->assertSame('myservice', $data->rawPayload['service']['name']);
    }

    public function test_it_rejects_negative_line_number(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GatewayLogData(
            lineNumber: -1,
            byteOffset: 0,
            consumerId: null,
            serviceId: null,
            serviceName: null,
            requestMethod: null,
            requestUri: null,
            responseStatus: null,
            latencies: new LatencyData(null, null, null),
            startedAt: null,
            createdAt: null,
            rawPayload: [],
        );
    }

    public function test_it_rejects_negative_byte_offset(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GatewayLogData(
            lineNumber: 1,
            byteOffset: -1,
            consumerId: null,
            serviceId: null,
            serviceName: null,
            requestMethod: null,
            requestUri: null,
            responseStatus: null,
            latencies: new LatencyData(null, null, null),
            startedAt: null,
            createdAt: null,
            rawPayload: [],
        );
    }

    public function test_it_rejects_invalid_response_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GatewayLogData(
            lineNumber: 1,
            byteOffset: 0,
            consumerId: null,
            serviceId: null,
            serviceName: null,
            requestMethod: null,
            requestUri: null,
            responseStatus: 999,
            latencies: new LatencyData(null, null, null),
            startedAt: null,
            createdAt: null,
            rawPayload: [],
        );
    }
}
