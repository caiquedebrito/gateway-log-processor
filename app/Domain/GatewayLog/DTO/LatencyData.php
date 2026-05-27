<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\DTO;

use InvalidArgumentException;

final readonly class LatencyData
{
    public function __construct(
        public ?int $request,
        public ?int $proxy,
        public ?int $gateway,
    ) {
        $this->ensureNonNegative('request', $this->request);
        $this->ensureNonNegative('proxy', $this->proxy);
        $this->ensureNonNegative('gateway', $this->gateway);
    }

    private function ensureNonNegative(string $field, ?int $value): void
    {
        if ($value !== null && $value < 0) {
            throw new InvalidArgumentException(
                "Latency field [{$field}] cannot be negative."
            );
        }
    }
}
