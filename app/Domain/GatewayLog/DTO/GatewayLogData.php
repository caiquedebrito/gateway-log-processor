<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\DTO;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class GatewayLogData
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public int $lineNumber,
        public int $byteOffset,
        public ?string $consumerId,
        public ?string $serviceId,
        public ?string $serviceName,
        public ?string $requestMethod,
        public ?string $requestUri,
        public ?int $responseStatus,
        public LatencyData $latencies,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $createdAt,
        public array $rawPayload,
    ) {
        $this->ensureNonNegative('lineNumber', $this->lineNumber);
        $this->ensureNonNegative('byteOffset', $this->byteOffset);
        $this->ensureValidResponseStatus($this->responseStatus);
    }

    private function ensureNonNegative(string $field, int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException(
                "Gateway log field [{$field}] cannot be negative."
            );
        }
    }

    private function ensureValidResponseStatus(?int $status): void
    {
        if ($status === null) {
            return;
        }

        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(
                'Response status must be between 100 and 599.'
            );
        }
    }
}
