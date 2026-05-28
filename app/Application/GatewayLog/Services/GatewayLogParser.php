<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Domain\GatewayLog\DTO\GatewayLogData;
use App\Domain\GatewayLog\DTO\LatencyData;
use App\Domain\GatewayLog\Exceptions\InvalidLogLineException;
use Carbon\CarbonImmutable;
use DateTimeZone;
use JsonException;

final class GatewayLogParser
{
    private DateTimeZone $timezone;

    public function __construct(string $timezone = 'UTC')
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function parse(string $line, int $lineNumber, int $byteOffset): GatewayLogData
    {
        $payload = $this->decodeJson($line, $lineNumber);

        $startedAt = $this->extractStartedAt($payload, $lineNumber);

        return new GatewayLogData(
            lineNumber: $lineNumber,
            byteOffset: $byteOffset,
            consumerId: $this->stringOrNull($payload['authenticated_entity']['consumer_id'] ?? null),
            serviceId: $this->stringOrNull($payload['service']['id'] ?? null),
            serviceName: $this->stringOrNull($payload['service']['name'] ?? null),
            requestMethod: $this->stringOrNull($payload['request']['method'] ?? null),
            requestUri: $this->stringOrNull($payload['request']['uri'] ?? null),
            responseStatus: $this->intOrNull($payload['response']['status'] ?? null),
            latencies: new LatencyData(
                request: $this->intOrNull($payload['latencies']['request'] ?? null),
                proxy: $this->intOrNull($payload['latencies']['proxy'] ?? null),
                gateway: $this->intOrNull($payload['latencies']['gateway'] ?? null),
            ),
            startedAt: $startedAt,
            createdAt: $startedAt,
            rawPayload: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $line, int $lineNumber): array
    {
        try {
            $payload = json_decode(
                json: trim($line),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw InvalidLogLineException::invalidJson($lineNumber, $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw InvalidLogLineException::invalidStructure($lineNumber);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractStartedAt(array $payload, int $lineNumber): CarbonImmutable
    {
        if (! array_key_exists('started_at', $payload)) {
            throw InvalidLogLineException::missingStartedAt($lineNumber);
        }

        $startedAt = $payload['started_at'];

        if (! is_int($startedAt) && ! ctype_digit((string) $startedAt)) {
            throw InvalidLogLineException::invalidStartedAt($lineNumber);
        }

        $startedAtMilliseconds = (int) $startedAt;

        if ($startedAtMilliseconds <= 0) {
            throw InvalidLogLineException::invalidStartedAt($lineNumber);
        }

        return $this->dateTimeFromMilliseconds($startedAtMilliseconds);
    }

    private function dateTimeFromMilliseconds(int $milliseconds): CarbonImmutable
    {
        $seconds = intdiv($milliseconds, 1000);
        $remainingMilliseconds = $milliseconds % 1000;

        return CarbonImmutable::createFromTimestamp($seconds, $this->timezone)
            ->addMilliseconds($remainingMilliseconds);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
