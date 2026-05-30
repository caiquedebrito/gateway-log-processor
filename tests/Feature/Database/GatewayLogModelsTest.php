<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use App\Models\LogImportError;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GatewayLogModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_log_import_with_enum_status_cast(): void
    {
        $import = LogImport::query()->create([
            'file_path' => 'storage/app/logs/logs.txt',
            'file_hash' => str_repeat('a', 64),
            'status' => LogImportStatus::Queued,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);

        $this->assertTrue($import->exists);
        $this->assertSame(LogImportStatus::Queued, $import->status);
        $this->assertSame(0, $import->current_offset);
        $this->assertSame(0, $import->last_line_number);
        $this->assertSame(0, $import->total_lines_processed);
        $this->assertSame(0, $import->total_lines_failed);
    }

    public function test_it_marks_a_log_import_as_processing(): void
    {
        $import = LogImport::query()->create([
            'file_path' => 'storage/app/logs/logs.txt',
            'file_hash' => str_repeat('b', 64),
            'status' => LogImportStatus::Queued,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);

        $import->markAsProcessing();

        $import->refresh();

        $this->assertSame(LogImportStatus::Processing, $import->status);
        $this->assertNotNull($import->started_at);
        $this->assertNull($import->failed_at);
        $this->assertNull($import->error_message);
    }

    public function test_it_marks_a_log_import_as_finished(): void
    {
        $import = LogImport::query()->create([
            'file_path' => 'storage/app/logs/logs.txt',
            'file_hash' => str_repeat('c', 64),
            'status' => LogImportStatus::Processing,
            'current_offset' => 120,
            'last_line_number' => 10,
            'total_lines_processed' => 10,
            'total_lines_failed' => 0,
        ]);

        $import->markAsFinished();

        $import->refresh();

        $this->assertSame(LogImportStatus::Finished, $import->status);
        $this->assertNotNull($import->finished_at);
        $this->assertTrue($import->isFinished());
    }

    public function test_it_marks_a_log_import_as_failed(): void
    {
        $import = LogImport::query()->create([
            'file_path' => 'storage/app/logs/logs.txt',
            'file_hash' => str_repeat('d', 64),
            'status' => LogImportStatus::Processing,
            'current_offset' => 120,
            'last_line_number' => 10,
            'total_lines_processed' => 10,
            'total_lines_failed' => 0,
        ]);

        $import->markAsFailed('Unexpected import error.');

        $import->refresh();

        $this->assertSame(LogImportStatus::Failed, $import->status);
        $this->assertNotNull($import->failed_at);
        $this->assertSame('Unexpected import error.', $import->error_message);
    }

    public function test_it_creates_an_api_gateway_log_preserving_original_created_at_and_processing_processed_at(): void
    {
        $import = LogImport::query()->create([
            'file_path' => 'storage/app/logs/logs.txt',
            'file_hash' => str_repeat('e', 64),
            'status' => LogImportStatus::Processing,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);

        $originalLogDate = CarbonImmutable::parse('2015-06-02 10:30:22', 'UTC');
        $processingDate = CarbonImmutable::parse('2026-05-28 12:00:00', 'UTC');

        $log = ApiGatewayLog::query()->create([
            'log_import_id' => $import->id,
            'event_hash' => hash('sha256', 'consumer-1-catalog-service-1'),
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
            'started_at' => $originalLogDate,
            'created_at' => $originalLogDate,
            'processed_at' => $processingDate,
            'raw_payload' => [
                'service' => [
                    'name' => 'myservice',
                ],
            ],
            'updated_at' => $processingDate,
        ]);

        $log->refresh();

        $this->assertSame('2015-06-02 10:30:22', $log->created_at->toDateTimeString());
        $this->assertSame('2015-06-02 10:30:22', $log->started_at->toDateTimeString());
        $this->assertSame('2026-05-28 12:00:00', $log->processed_at->toDateTimeString());

        $this->assertTrue($log->created_at->notEqualTo($log->processed_at));

        $this->assertIsArray($log->raw_payload);
        $this->assertSame('myservice', $log->raw_payload['service']['name']);
    }

    public function test_it_relates_an_api_gateway_log_to_its_import(): void
    {
        $import = LogImport::query()->create([
            'file_path' => 'storage/app/logs/logs.txt',
            'file_hash' => str_repeat('f', 64),
            'status' => LogImportStatus::Processing,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);

        $date = CarbonImmutable::parse('2026-05-28 12:00:00', 'UTC');

        $log = ApiGatewayLog::query()->create([
            'log_import_id' => $import->id,
            'event_hash' => hash('sha256', 'consumer-2-catalog-service-1'),
            'line_number' => 1,
            'byte_offset' => 0,
            'consumer_id' => 'consumer-1',
            'service_id' => 'service-1',
            'service_name' => 'catalog-service',
            'request_method' => 'GET',
            'request_uri' => '/products',
            'response_status' => 200,
            'latency_request' => 100,
            'latency_proxy' => 80,
            'latency_gateway' => 20,
            'started_at' => $date,
            'created_at' => $date,
            'processed_at' => $date,
            'raw_payload' => ['test' => true],
            'updated_at' => $date,
        ]);

        $this->assertTrue($log->import->is($import));
        $this->assertSame(1, $import->apiGatewayLogs()->count());
    }

    public function test_it_creates_a_log_import_error_and_relates_it_to_its_import(): void
    {
        $import = LogImport::query()->create([
            'file_path' => 'storage/app/logs/logs.txt',
            'file_hash' => str_repeat('g', 64),
            'status' => LogImportStatus::Processing,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);

        $error = LogImportError::query()->create([
            'log_import_id' => $import->id,
            'line_number' => 2,
            'byte_offset' => 150,
            'error_message' => 'Invalid JSON at line 2.',
            'raw_line' => '{invalid-json',
        ]);

        $this->assertTrue($error->import->is($import));
        $this->assertSame(1, $import->importErrors()->count());
        $this->assertSame(2, $error->line_number);
        $this->assertSame(150, $error->byte_offset);
    }

    public function test_it_creates_a_report_export_with_enum_casts(): void
    {
        $report = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
        ]);

        $this->assertTrue($report->exists);
        $this->assertSame(ReportType::RequestsByConsumer, $report->type);
        $this->assertSame(ReportExportStatus::Queued, $report->status);
    }

    public function test_it_marks_a_report_export_as_processing(): void
    {
        $report = ReportExport::query()->create([
            'type' => ReportType::RequestsByService,
            'status' => ReportExportStatus::Queued,
        ]);

        $report->markAsProcessing();

        $report->refresh();

        $this->assertSame(ReportExportStatus::Processing, $report->status);
        $this->assertNotNull($report->started_at);
        $this->assertNull($report->failed_at);
        $this->assertNull($report->error_message);
    }

    public function test_it_marks_a_report_export_as_finished(): void
    {
        $report = ReportExport::query()->create([
            'type' => ReportType::AverageLatencyByService,
            'status' => ReportExportStatus::Processing,
        ]);

        $report->markAsFinished('reports/average_latency_by_service.csv');

        $report->refresh();

        $this->assertSame(ReportExportStatus::Finished, $report->status);
        $this->assertSame('reports/average_latency_by_service.csv', $report->output_path);
        $this->assertNotNull($report->finished_at);
    }

    public function test_it_marks_a_report_export_as_failed(): void
    {
        $report = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Processing,
        ]);

        $report->markAsFailed('Could not write CSV file.');

        $report->refresh();

        $this->assertSame(ReportExportStatus::Failed, $report->status);
        $this->assertNotNull($report->failed_at);
        $this->assertSame('Could not write CSV file.', $report->error_message);
    }
}
