<?php

declare(strict_types=1);

namespace Tests\Unit\Application\GatewayLog\Services;

use App\Application\GatewayLog\Services\GatewayLogParser;
use App\Domain\GatewayLog\DTO\GatewayLogData;
use App\Domain\GatewayLog\Exceptions\InvalidLogLineException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GatewayLogParserTest extends TestCase
{
    private GatewayLogParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new GatewayLogParser('UTC');
    }

    public function test_it_parses_valid_gateway_log_line(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertInstanceOf(GatewayLogData::class, $data);
    }

    public function test_it_extracts_consumer_id(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertSame(
            '80f74eef-31b8-45d5-c525-ae532297ea8e',
            $data->consumerId,
        );
    }

    public function test_it_extracts_service_data(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertSame(
            '0590139e-7481-466c-bcdf-929adcaaf804',
            $data->serviceId,
        );

        $this->assertSame('myservice', $data->serviceName);
    }

    public function test_it_extracts_request_data(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertSame('GET', $data->requestMethod);
        $this->assertSame('/get', $data->requestUri);
    }

    public function test_it_extracts_response_status(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertSame(200, $data->responseStatus);
    }

    public function test_it_extracts_latencies(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertSame(1921, $data->latencies->request);
        $this->assertSame(1430, $data->latencies->proxy);
        $this->assertSame(9, $data->latencies->gateway);
    }

    public function test_it_converts_started_at_from_milliseconds_to_datetime(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertNotNull($data->startedAt);
        $this->assertSame('2015-06-02 01:50:22.425000', $data->startedAt->format('Y-m-d H:i:s.u'));
    }

    public function test_it_sets_created_at_from_started_at(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertNotNull($data->startedAt);
        $this->assertNotNull($data->createdAt);
        $this->assertTrue($data->startedAt->equalTo($data->createdAt));
    }

    public function test_it_keeps_line_metadata(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 10,
            byteOffset: 250,
        );

        $this->assertSame(10, $data->lineNumber);
        $this->assertSame(250, $data->byteOffset);
    }

    public function test_it_keeps_raw_payload(): void
    {
        $data = $this->parser->parse(
            line: $this->validLogLine(),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertSame('myservice', $data->rawPayload['service']['name']);
        $this->assertSame(200, $data->rawPayload['response']['status']);
    }

    public function test_it_throws_exception_for_invalid_json(): void
    {
        $this->expectException(InvalidLogLineException::class);
        $this->expectExceptionMessage('Invalid JSON at line 1.');

        $this->parser->parse(
            line: '{"request": {"method": "GET"',
            lineNumber: 1,
            byteOffset: 0,
        );
    }

    public function test_it_throws_exception_for_non_object_json(): void
    {
        $this->expectException(InvalidLogLineException::class);
        $this->expectExceptionMessage('Invalid log structure at line 1.');

        $this->parser->parse(
            line: '["invalid", "structure"]',
            lineNumber: 1,
            byteOffset: 0,
        );
    }

    public function test_it_throws_exception_when_started_at_is_missing(): void
    {
        $this->expectException(InvalidLogLineException::class);
        $this->expectExceptionMessage('Missing started_at at line 1.');

        $payload = [
            'request' => [
                'method' => 'GET',
                'uri' => '/get',
            ],
            'response' => [
                'status' => 200,
            ],
        ];

        $this->parser->parse(
            line: json_encode($payload, JSON_THROW_ON_ERROR),
            lineNumber: 1,
            byteOffset: 0,
        );
    }

    public function test_it_throws_exception_when_started_at_is_invalid(): void
    {
        $this->expectException(InvalidLogLineException::class);
        $this->expectExceptionMessage('Invalid started_at at line 1.');

        $payload = [
            'started_at' => 'invalid',
            'request' => [
                'method' => 'GET',
                'uri' => '/get',
            ],
            'response' => [
                'status' => 200,
            ],
        ];

        $this->parser->parse(
            line: json_encode($payload, JSON_THROW_ON_ERROR),
            lineNumber: 1,
            byteOffset: 0,
        );
    }

    public function test_it_extracts_consumer_id_when_it_is_a_string(): void
    {
        $payload = $this->validPayload([
            'authenticated_entity' => [
                'consumer_id' => '80f74eef-31b8-45d5-c525-ae532297ea8e',
            ],
        ]);

        $data = $this->parser->parse(
            line: json_encode($payload, JSON_THROW_ON_ERROR),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertSame(
            '80f74eef-31b8-45d5-c525-ae532297ea8e',
            $data->consumerId,
        );
    }

    public function test_it_extracts_consumer_id_when_it_is_an_object_with_uuid(): void
    {
        $payload = $this->validPayload([
            'authenticated_entity' => [
                'consumer_id' => [
                    'uuid' => '72b34d31-4c14-3bae-9cc6-516a0939c9d6',
                ],
            ],
        ]);

        $data = $this->parser->parse(
            line: json_encode($payload, JSON_THROW_ON_ERROR),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertSame(
            '72b34d31-4c14-3bae-9cc6-516a0939c9d6',
            $data->consumerId,
        );
    }

    private function validLogLine(): string
    {
        return json_encode([
            'request' => [
                'method' => 'GET',
                'uri' => '/get',
                'url' => 'http://httpbin.org:8000/get',
            ],
            'response' => [
                'status' => 200,
                'size' => '434',
            ],
            'authenticated_entity' => [
                'consumer_id' => '80f74eef-31b8-45d5-c525-ae532297ea8e',
            ],
            'service' => [
                'id' => '0590139e-7481-466c-bcdf-929adcaaf804',
                'name' => 'myservice',
            ],
            'latencies' => [
                'proxy' => 1430,
                'gateway' => 9,
                'request' => 1921,
            ],
            'client_ip' => '127.0.0.1',
            'started_at' => 1433209822425,
        ], JSON_THROW_ON_ERROR);
    }

    #[DataProvider('startedAtTimezoneProvider')]
    public function test_it_converts_started_at_from_milliseconds_using_configured_timezone(
        string $timezone,
        int|string $startedAt,
        string $expected
    ): void {
        $parser = new GatewayLogParser($timezone);

        $data = $parser->parse(
            line: json_encode([
                'started_at' => $startedAt,
            ], JSON_THROW_ON_ERROR),
            lineNumber: 1,
            byteOffset: 0,
        );

        $this->assertSame(
            $expected,
            $data->startedAt->format('Y-m-d H:i:s.u P')
        );
    }

    private function validPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'request' => [
                'method' => 'GET',
                'uri' => '/',
                'url' => 'http://yost.com',
                'size' => 174,
            ],
            'response' => [
                'status' => 500,
                'size' => 878,
            ],
            'authenticated_entity' => [
                'consumer_id' => 'default-consumer-id',
            ],
            'service' => [
                'id' => 'c3e86413-648a-3552-90c3-b13491ee07d6',
                'name' => 'ritchie',
            ],
            'latencies' => [
                'proxy' => 1836,
                'gateway' => 8,
                'request' => 1058,
            ],
            'client_ip' => '75.241.168.121',
            'started_at' => 1566660387,
        ], $overrides);
    }

    /**
     * @return array<string, array{timezone: string, startedAt: int|string, expected: string}>
     */
    public static function startedAtTimezoneProvider(): array
    {
        return [
            'utc timestamp 2015 with milliseconds' => [
                'timezone' => 'UTC',
                'startedAt' => 1433209822425,
                'expected' => '2015-06-02 01:50:22.425000 +00:00',
            ],

            'america sao paulo timestamp 2015 with milliseconds' => [
                'timezone' => 'America/Sao_Paulo',
                'startedAt' => 1433209822425,
                'expected' => '2015-06-01 22:50:22.425000 -03:00',
            ],

            'america new york timestamp 2015 with milliseconds' => [
                'timezone' => 'America/New_York',
                'startedAt' => 1433209822425,
                'expected' => '2015-06-01 21:50:22.425000 -04:00',
            ],

            'asia tokyo timestamp 2015 with milliseconds' => [
                'timezone' => 'Asia/Tokyo',
                'startedAt' => 1433209822425,
                'expected' => '2015-06-02 10:50:22.425000 +09:00',
            ],

            'utc timestamp 2024 with milliseconds' => [
                'timezone' => 'UTC',
                'startedAt' => 1704067200123,
                'expected' => '2024-01-01 00:00:00.123000 +00:00',
            ],

            'america sao paulo timestamp 2024 with milliseconds' => [
                'timezone' => 'America/Sao_Paulo',
                'startedAt' => 1704067200123,
                'expected' => '2023-12-31 21:00:00.123000 -03:00',
            ],

            'america new york timestamp 2024 with milliseconds' => [
                'timezone' => 'America/New_York',
                'startedAt' => 1704067200123,
                'expected' => '2023-12-31 19:00:00.123000 -05:00',
            ],

            'asia tokyo timestamp 2024 with milliseconds' => [
                'timezone' => 'Asia/Tokyo',
                'startedAt' => 1704067200123,
                'expected' => '2024-01-01 09:00:00.123000 +09:00',
            ],

            'numeric string timestamp is accepted' => [
                'timezone' => 'UTC',
                'startedAt' => '1704067200123',
                'expected' => '2024-01-01 00:00:00.123000 +00:00',
            ],
        ];
    }
}
