<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Domain\GatewayLog\DTO\GatewayLogData;
use Carbon\CarbonImmutable;
use JsonException;

final readonly class GatewayLogEventHashGenerator
{
    /**
     * @throws JsonException
     */
    public function generate(GatewayLogData $data): string
    {
        $fingerprint = [
            'started_at_ms' => $this->startedAtInMilliseconds($data),
            'consumer_id' => $this->normalizeString($data->consumerId),
            'service_id' => $this->normalizeString($data->serviceId),
            'service_name' => $this->normalizeString($data->serviceName),
            'request_method' => $this->normalizeMethod($data->requestMethod),
            'request_uri' => $this->normalizeString($data->requestUri),
            'request_querystring' => $this->normalizeValue(
                $data->rawPayload['request']['querystring'] ?? null
            ),
            'response_status' => $data->responseStatus,
            'latency_request' => $data->latencies->request,
            'latency_proxy' => $data->latencies->proxy,
            'latency_gateway' => $data->latencies->gateway,
            'client_ip' => $this->normalizeString($data->rawPayload['client_ip'] ?? null),
        ];

        return hash(
            algo: 'sha256',
            data: json_encode(
                value: $fingerprint,
                flags: JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function startedAtInMilliseconds(GatewayLogData $data): ?int
    {
        $rawStartedAt = $data->rawPayload['started_at'] ?? null;

        if (is_int($rawStartedAt)) {
            return $rawStartedAt;
        }

        if (is_string($rawStartedAt) && ctype_digit($rawStartedAt)) {
            return (int) $rawStartedAt;
        }

        if (! $data->startedAt instanceof CarbonImmutable) {
            return null;
        }

        return ((int) $data->startedAt->format('U') * 1000)
            + intdiv((int) $data->startedAt->format('u'), 1000);
    }

    private function normalizeMethod(?string $method): ?string
    {
        if ($method === null) {
            return null;
        }

        return strtoupper(trim($method));
    }

    private function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return null;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->normalizeValue($item),
                $value,
            );
        }

        ksort($value);

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->normalizeValue($item);
        }

        return $normalized;
    }
}
