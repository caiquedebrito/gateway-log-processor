<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogDatabaseStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_imports_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('log_imports'));

        $this->assertTrue(Schema::hasColumns('log_imports', [
            'id',
            'file_path',
            'file_hash',
            'status',
            'current_offset',
            'last_line_number',
            'total_lines_processed',
            'total_lines_failed',
            'started_at',
            'finished_at',
            'failed_at',
            'error_message',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_api_gateway_logs_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('api_gateway_logs'));

        $this->assertTrue(Schema::hasColumns('api_gateway_logs', [
            'id',
            'log_import_id',
            'line_number',
            'byte_offset',
            'consumer_id',
            'service_id',
            'service_name',
            'request_method',
            'request_uri',
            'response_status',
            'latency_request',
            'latency_proxy',
            'latency_gateway',
            'started_at',
            'created_at',
            'processed_at',
            'raw_payload',
            'updated_at',
        ]));
    }

    public function test_log_import_errors_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('log_import_errors'));

        $this->assertTrue(Schema::hasColumns('log_import_errors', [
            'id',
            'log_import_id',
            'line_number',
            'byte_offset',
            'error_message',
            'raw_line',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_report_exports_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('report_exports'));

        $this->assertTrue(Schema::hasColumns('report_exports', [
            'id',
            'type',
            'status',
            'output_path',
            'started_at',
            'finished_at',
            'failed_at',
            'error_message',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_log_import_file_hash_must_be_unique(): void
    {
        DB::table('log_imports')->insert([
            'file_path' => 'storage/app/imports/logs.txt',
            'file_hash' => hash('sha256', 'same-file'),
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('log_imports')->insert([
            'file_path' => 'storage/app/imports/logs-copy.txt',
            'file_hash' => hash('sha256', 'same-file'),
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_api_gateway_logs_prevents_duplicate_line_for_same_import(): void
    {
        $importId = DB::table('log_imports')->insertGetId([
            'file_path' => 'storage/app/imports/logs.txt',
            'file_hash' => hash('sha256', 'logs-file'),
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'log_import_id' => $importId,
            'line_number' => 1,
            'byte_offset' => 0,
            'consumer_id' => '80f74eef-31b8-45d5-c525-ae532297ea8e',
            'service_id' => '0590139e-7481-466c-bcdf-929adcaaf804',
            'service_name' => 'myservice',
            'request_method' => 'GET',
            'request_uri' => '/get',
            'response_status' => 200,
            'latency_request' => 1921,
            'latency_proxy' => 1430,
            'latency_gateway' => 9,
            'started_at' => now(),
            'created_at' => now(),
            'processed_at' => now(),
            'raw_payload' => json_encode(['line' => 'payload']),
            'updated_at' => now(),
        ];

        DB::table('api_gateway_logs')->insert($payload);

        $this->expectException(QueryException::class);

        DB::table('api_gateway_logs')->insert($payload);
    }

    public function test_import_can_have_many_log_errors(): void
    {
        $importId = DB::table('log_imports')->insertGetId([
            'file_path' => 'storage/app/imports/logs.txt',
            'file_hash' => hash('sha256', 'logs-with-errors'),
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('log_import_errors')->insert([
            [
                'log_import_id' => $importId,
                'line_number' => 1,
                'byte_offset' => 0,
                'error_message' => 'Invalid JSON.',
                'raw_line' => '{invalid-json',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'log_import_id' => $importId,
                'line_number' => 2,
                'byte_offset' => 20,
                'error_message' => 'Missing started_at.',
                'raw_line' => '{"service":{}}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertDatabaseCount('log_import_errors', 2);

        $this->assertDatabaseHas('log_import_errors', [
            'log_import_id' => $importId,
            'line_number' => 1,
            'error_message' => 'Invalid JSON.',
        ]);

        $this->assertDatabaseHas('log_import_errors', [
            'log_import_id' => $importId,
            'line_number' => 2,
            'error_message' => 'Missing started_at.',
        ]);
    }
}
