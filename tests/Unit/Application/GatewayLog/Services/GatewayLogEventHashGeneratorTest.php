<?php

declare(strict_types=1);

namespace Tests\Unit\Application\GatewayLog\Services;

use App\Application\GatewayLog\Services\GatewayLogEventHashGenerator;
use App\Domain\GatewayLog\DTO\GatewayLogData;
use App\Domain\GatewayLog\DTO\LatencyData;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class GatewayLogEventHashGeneratorTest extends TestCase
{
    public function test_it_generates_a_valid_sha256_hash(): void
    {
        $hash = $this->makeGenerator()->generate(
            $this->makeGatewayLogData()
        );

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_it_generates_the_same_hash_for_the_same_gateway_log_data(): void
    {
        $generator = $this->makeGenerator();

        $data = $this->makeGatewayLogData();

        $firstHash = $generator->generate($data);
        $secondHash = $generator->generate($data);

        $this->assertSame($firstHash, $secondHash);
    }

    public function test_it_generates_the_same_hash_even_when_raw_payload_order_changes(): void
    {
        $generator = $this->makeGenerator();

        $firstData = $this->makeGatewayLogData([
            'rawPayload' => [
                'request' => [
                    'method' => 'GET',
                    'uri' => '/products',
                    'querystring' => [
                        'page' => '1',
                        'sort' => 'name',
                    ],
                ],
                'response' => [
                    'status' => 200,
                ],
                'authenticated_entity' => [
                    'consumer_id' => 'consumer-1',
                ],
                'service' => [
                    'id' => 'service-1',
                    'name' => 'catalog-service',
                ],
                'latencies' => [
                    'request' => 120,
                    'proxy' => 90,
                    'gateway' => 30,
                ],
                'client_ip' => '127.0.0.1',
                'started_at' => 1433209822425,
            ],
        ]);

        $secondData = $this->makeGatewayLogData([
            'rawPayload' => [
                'started_at' => 1433209822425,
                'client_ip' => '127.0.0.1',
                'latencies' => [
                    'gateway' => 30,
                    'proxy' => 90,
                    'request' => 120,
                ],
                'service' => [
                    'name' => 'catalog-service',
                    'id' => 'service-1',
                ],
                'authenticated_entity' => [
                    'consumer_id' => 'consumer-1',
                ],
                'response' => [
                    'status' => 200,
                ],
                'request' => [
                    'querystring' => [
                        'sort' => 'name',
                        'page' => '1',
                    ],
                    'uri' => '/products',
                    'method' => 'GET',
                ],
            ],
        ]);

        $this->assertSame(
            $generator->generate($firstData),
            $generator->generate($secondData),
        );
    }

    public function test_it_generates_different_hash_when_started_at_changes(): void
    {
        $generator = $this->makeGenerator();

        $firstHash = $generator->generate(
            $this->makeGatewayLogData([
                'startedAtMilliseconds' => 1433209822425,
            ])
        );

        $secondHash = $generator->generate(
            $this->makeGatewayLogData([
                'startedAtMilliseconds' => 1433209822426,
            ])
        );

        $this->assertNotSame($firstHash, $secondHash);
    }

    public function test_it_generates_different_hash_when_consumer_changes(): void
    {
        $generator = $this->makeGenerator();

        $firstHash = $generator->generate(
            $this->makeGatewayLogData([
                'consumerId' => 'consumer-1',
            ])
        );

        $secondHash = $generator->generate(
            $this->makeGatewayLogData([
                'consumerId' => 'consumer-2',
            ])
        );

        $this->assertNotSame($firstHash, $secondHash);
    }

    public function test_it_generates_different_hash_when_service_changes(): void
    {
        $generator = $this->makeGenerator();

        $firstHash = $generator->generate(
            $this->makeGatewayLogData([
                'serviceId' => 'service-1',
                'serviceName' => 'catalog-service',
            ])
        );

        $secondHash = $generator->generate(
            $this->makeGatewayLogData([
                'serviceId' => 'service-2',
                'serviceName' => 'billing-service',
            ])
        );

        $this->assertNotSame($firstHash, $secondHash);
    }

    public function test_it_generates_different_hash_when_request_uri_changes(): void
    {
        $generator = $this->makeGenerator();

        $firstHash = $generator->generate(
            $this->makeGatewayLogData([
                'requestUri' => '/products',
            ])
        );

        $secondHash = $generator->generate(
            $this->makeGatewayLogData([
                'requestUri' => '/orders',
            ])
        );

        $this->assertNotSame($firstHash, $secondHash);
    }

    public function test_it_generates_different_hash_when_querystring_changes(): void
    {
        $generator = $this->makeGenerator();

        $firstHash = $generator->generate(
            $this->makeGatewayLogData([
                'querystring' => [
                    'page' => '1',
                ],
            ])
        );

        $secondHash = $generator->generate(
            $this->makeGatewayLogData([
                'querystring' => [
                    'page' => '2',
                ],
            ])
        );

        $this->assertNotSame($firstHash, $secondHash);
    }

    public function test_it_generates_different_hash_when_latency_changes(): void
    {
        $generator = $this->makeGenerator();

        $firstHash = $generator->generate(
            $this->makeGatewayLogData([
                'latencyRequest' => 120,
                'latencyProxy' => 90,
                'latencyGateway' => 30,
            ])
        );

        $secondHash = $generator->generate(
            $this->makeGatewayLogData([
                'latencyRequest' => 130,
                'latencyProxy' => 90,
                'latencyGateway' => 30,
            ])
        );

        $this->assertNotSame($firstHash, $secondHash);
    }

    private function makeGenerator(): GatewayLogEventHashGenerator
    {
        return new GatewayLogEventHashGenerator;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeGatewayLogData(array $overrides = []): GatewayLogData
    {
        $startedAtMilliseconds = $overrides['startedAtMilliseconds'] ?? 1433209822425;

        $consumerId = $overrides['consumerId'] ?? 'consumer-1';
        $serviceId = $overrides['serviceId'] ?? 'service-1';
        $serviceName = $overrides['serviceName'] ?? 'catalog-service';
        $requestMethod = $overrides['requestMethod'] ?? 'GET';
        $requestUri = $overrides['requestUri'] ?? '/products';
        $responseStatus = $overrides['responseStatus'] ?? 200;

        $latencyRequest = $overrides['latencyRequest'] ?? 120;
        $latencyProxy = $overrides['latencyProxy'] ?? 90;
        $latencyGateway = $overrides['latencyGateway'] ?? 30;

        $querystring = $overrides['querystring'] ?? [];

        $startedAt = CarbonImmutable::createFromTimestamp(
            intdiv((int) $startedAtMilliseconds, 1000),
            'UTC',
        )->addMilliseconds((int) $startedAtMilliseconds % 1000);

        $rawPayload = $overrides['rawPayload'] ?? [
            'request' => [
                'method' => $requestMethod,
                'uri' => $requestUri,
                'querystring' => $querystring,
            ],
            'response' => [
                'status' => $responseStatus,
            ],
            'authenticated_entity' => [
                'consumer_id' => $consumerId,
            ],
            'service' => [
                'id' => $serviceId,
                'name' => $serviceName,
            ],
            'latencies' => [
                'request' => $latencyRequest,
                'proxy' => $latencyProxy,
                'gateway' => $latencyGateway,
            ],
            'client_ip' => '127.0.0.1',
            'started_at' => $startedAtMilliseconds,
        ];

        return new GatewayLogData(
            lineNumber: 1,
            byteOffset: 0,
            consumerId: $consumerId,
            serviceId: $serviceId,
            serviceName: $serviceName,
            requestMethod: $requestMethod,
            requestUri: $requestUri,
            responseStatus: $responseStatus,
            latencies: new LatencyData(
                request: $latencyRequest,
                proxy: $latencyProxy,
                gateway: $latencyGateway,
            ),
            startedAt: $startedAt,
            createdAt: $startedAt,
            rawPayload: $rawPayload,
        );
    }
}
