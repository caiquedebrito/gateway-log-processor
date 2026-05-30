<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Domain\GatewayLog\DTO\GatewayLogData;
use App\Domain\GatewayLog\DTO\LatencyData;
use App\Models\ApiGatewayLog;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class ApiGatewayLogPayloadTest extends TestCase
{
    public function test_it_builds_insert_payload_with_event_hash(): void
    {
        $startedAt = CarbonImmutable::parse('2015-06-02 01:50:22', 'UTC');
        $processedAt = CarbonImmutable::parse('2026-05-28 19:30:00', 'UTC');

        $data = new GatewayLogData(
            lineNumber: 10,
            byteOffset: 250,
            consumerId: 'consumer-1',
            serviceId: 'service-1',
            serviceName: 'catalog-service',
            requestMethod: 'GET',
            requestUri: '/products',
            responseStatus: 200,
            latencies: new LatencyData(
                request: 120,
                proxy: 90,
                gateway: 30,
            ),
            startedAt: $startedAt,
            createdAt: $startedAt,
            rawPayload: [
                'request' => [
                    'method' => 'GET',
                    'uri' => '/products',
                ],
                'service' => [
                    'id' => 'service-1',
                    'name' => 'catalog-service',
                ],
                'started_at' => 1433209822425,
            ],
        );

        $payload = ApiGatewayLog::makeInsertPayload(
            logImportId: 1,
            data: $data,
            eventHash: str_repeat('a', 64),
            processedAt: $processedAt,
        );

        $this->assertSame(1, $payload['log_import_id']);
        $this->assertSame(str_repeat('a', 64), $payload['event_hash']);
        $this->assertSame(10, $payload['line_number']);
        $this->assertSame(250, $payload['byte_offset']);
        $this->assertSame('consumer-1', $payload['consumer_id']);
        $this->assertSame('service-1', $payload['service_id']);
        $this->assertSame('catalog-service', $payload['service_name']);
        $this->assertSame('GET', $payload['request_method']);
        $this->assertSame('/products', $payload['request_uri']);
        $this->assertSame(200, $payload['response_status']);
        $this->assertSame(120, $payload['latency_request']);
        $this->assertSame(90, $payload['latency_proxy']);
        $this->assertSame(30, $payload['latency_gateway']);
        $this->assertSame($startedAt, $payload['started_at']);
        $this->assertSame($startedAt, $payload['created_at']);
        $this->assertSame($processedAt, $payload['processed_at']);
        $this->assertSame($processedAt, $payload['updated_at']);

        $this->assertJson($payload['raw_payload']);
    }
}
