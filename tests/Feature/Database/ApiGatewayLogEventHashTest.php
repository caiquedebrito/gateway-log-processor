<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiGatewayLogEventHashTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_event_hash_on_api_gateway_log(): void
    {
        $import = $this->createImport();

        $log = $this->createGatewayLog($import, [
            'event_hash' => str_repeat('a', 64),
        ]);

        $log->refresh();

        $this->assertSame(str_repeat('a', 64), $log->event_hash);
    }

    public function test_it_does_not_allow_duplicate_event_hash(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog($import, [
            'event_hash' => str_repeat('b', 64),
            'line_number' => 1,
            'byte_offset' => 0,
        ]);

        $this->expectException(QueryException::class);

        $this->createGatewayLog($import, [
            'event_hash' => str_repeat('b', 64),
            'line_number' => 2,
            'byte_offset' => 100,
        ]);
    }

    public function test_it_allows_different_event_hashes(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog($import, [
            'event_hash' => str_repeat('c', 64),
            'line_number' => 1,
            'byte_offset' => 0,
        ]);

        $this->createGatewayLog($import, [
            'event_hash' => str_repeat('d', 64),
            'line_number' => 2,
            'byte_offset' => 100,
        ]);

        $this->assertSame(2, ApiGatewayLog::query()->count());
    }

    private function createImport(): LogImport
    {
        return LogImport::query()->create([
            'file_path' => 'storage/app/logs/logs.txt',
            'file_hash' => str_repeat('f', 64),
            'status' => LogImportStatus::Processing,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGatewayLog(LogImport $import, array $overrides = []): ApiGatewayLog
    {
        $date = CarbonImmutable::parse('2026-05-28 12:00:00', 'UTC');

        return ApiGatewayLog::query()->create(array_merge([
            'log_import_id' => $import->id,
            'event_hash' => hash('sha256', uniqid('event-', true)),
            'line_number' => 1,
            'byte_offset' => 0,
            'consumer_id' => 'consumer-1',
            'service_id' => 'service-1',
            'service_name' => 'catalog-service',
            'request_method' => 'GET',
            'request_uri' => '/products',
            'response_status' => 200,
            'latency_request' => 120,
            'latency_proxy' => 90,
            'latency_gateway' => 30,
            'started_at' => $date,
            'created_at' => $date,
            'processed_at' => $date,
            'raw_payload' => [
                'service' => [
                    'name' => 'catalog-service',
                ],
            ],
            'updated_at' => $date,
        ], $overrides));
    }
}
