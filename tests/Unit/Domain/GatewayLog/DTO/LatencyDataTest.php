<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GatewayLog\DTO;

use App\Domain\GatewayLog\DTO\LatencyData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LatencyDataTest extends TestCase
{
    public function test_it_can_be_created(): void
    {
        $latency = new LatencyData(
            request: 1921,
            proxy: 1430,
            gateway: 9,
        );

        $this->assertSame(1921, $latency->request);
        $this->assertSame(1430, $latency->proxy);
        $this->assertSame(9, $latency->gateway);
    }

    public function test_it_allows_null_values(): void
    {
        $latency = new LatencyData(
            request: null,
            proxy: null,
            gateway: null,
        );

        $this->assertNull($latency->request);
        $this->assertNull($latency->proxy);
        $this->assertNull($latency->gateway);
    }

    public function test_it_rejects_negative_request_latency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Latency field [request] cannot be negative.');

        new LatencyData(
            request: -1,
            proxy: 1430,
            gateway: 9,
        );
    }

    public function test_it_rejects_negative_proxy_latency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Latency field [proxy] cannot be negative.');

        new LatencyData(
            request: 1921,
            proxy: -1,
            gateway: 9,
        );
    }

    public function test_it_rejects_negative_gateway_latency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Latency field [gateway] cannot be negative.');

        new LatencyData(
            request: 1921,
            proxy: 1430,
            gateway: -1,
        );
    }

    public function test_it_allows_zero_values(): void
    {
        $latency = new LatencyData(
            request: 0,
            proxy: 0,
            gateway: 0,
        );

        $this->assertSame(0, $latency->request);
        $this->assertSame(0, $latency->proxy);
        $this->assertSame(0, $latency->gateway);
    }
}
