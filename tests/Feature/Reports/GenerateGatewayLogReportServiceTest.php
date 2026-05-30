<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\GatewayLog\Reports\GatewayLogReportFactory;
use App\Application\GatewayLog\Services\CsvReportWriter;
use App\Application\GatewayLog\Services\GenerateGatewayLogReportService;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class GenerateGatewayLogReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'gateway-log-generate-report-'
            .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_generates_a_queued_requests_by_consumer_report(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog(
            import: $import,
            lineNumber: 1,
            consumerId: 'consumer-a',
            serviceName: 'catalog-service',
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 2,
            consumerId: 'consumer-a',
            serviceName: 'billing-service',
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 3,
            consumerId: 'consumer-b',
            serviceName: 'catalog-service',
        );

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
        ]);

        $generatedExport = $this->makeService()->generate(
            export: $export,
            outputDirectory: $this->temporaryDirectory,
        );

        $this->assertSame($export->id, $generatedExport->id);
        $this->assertSame(ReportType::RequestsByConsumer, $generatedExport->type);
        $this->assertSame(ReportExportStatus::Finished, $generatedExport->status);

        $this->assertNotNull($generatedExport->started_at);
        $this->assertNotNull($generatedExport->finished_at);
        $this->assertNull($generatedExport->failed_at);
        $this->assertNull($generatedExport->error_message);

        $this->assertFileExists($generatedExport->output_path);

        $rows = $this->readCsv($generatedExport->output_path);

        $this->assertSame([
            ['consumer_id', 'total'],
            ['consumer-a', '2'],
            ['consumer-b', '1'],
        ], $rows);
    }

    public function test_it_generates_a_queued_requests_by_service_report(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog(
            import: $import,
            lineNumber: 1,
            consumerId: 'consumer-a',
            serviceName: 'catalog-service',
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 2,
            consumerId: 'consumer-b',
            serviceName: 'catalog-service',
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 3,
            consumerId: 'consumer-c',
            serviceName: 'billing-service',
        );

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByService,
            'status' => ReportExportStatus::Queued,
        ]);

        $generatedExport = $this->makeService()->generate(
            export: $export,
            outputDirectory: $this->temporaryDirectory,
        );

        $this->assertSame(ReportExportStatus::Finished, $generatedExport->status);
        $this->assertFileExists($generatedExport->output_path);

        $rows = $this->readCsv($generatedExport->output_path);

        $this->assertSame([
            ['service_name', 'total'],
            ['catalog-service', '2'],
            ['billing-service', '1'],
        ], $rows);
    }

    public function test_it_generates_a_queued_average_latency_by_service_report(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog(
            import: $import,
            lineNumber: 1,
            consumerId: 'consumer-a',
            serviceName: 'catalog-service',
            latencyRequest: 100,
            latencyProxy: 60,
            latencyGateway: 10,
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 2,
            consumerId: 'consumer-b',
            serviceName: 'catalog-service',
            latencyRequest: 200,
            latencyProxy: 80,
            latencyGateway: 20,
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 3,
            consumerId: 'consumer-c',
            serviceName: 'billing-service',
            latencyRequest: 300,
            latencyProxy: 200,
            latencyGateway: 30,
        );

        $export = ReportExport::query()->create([
            'type' => ReportType::AverageLatencyByService,
            'status' => ReportExportStatus::Queued,
        ]);

        $generatedExport = $this->makeService()->generate(
            export: $export,
            outputDirectory: $this->temporaryDirectory,
        );

        $this->assertSame(ReportExportStatus::Finished, $generatedExport->status);
        $this->assertFileExists($generatedExport->output_path);

        $rows = $this->readCsv($generatedExport->output_path);

        $this->assertSame([
            [
                'service_name',
                'avg_latency_request',
                'avg_latency_proxy',
                'avg_latency_gateway',
            ],
            [
                'billing-service',
                '300.00',
                '200.00',
                '30.00',
            ],
            [
                'catalog-service',
                '150.00',
                '70.00',
                '15.00',
            ],
        ], $rows);
    }

    public function test_it_marks_report_as_failed_when_csv_cannot_be_written(): void
    {
        $invalidOutputDirectory = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'not-a-directory';

        file_put_contents($invalidOutputDirectory, 'content');

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
        ]);

        try {
            $this->makeService()->generate(
                export: $export,
                outputDirectory: $invalidOutputDirectory,
            );

            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exists and is not a directory', $exception->getMessage());
        }

        $export->refresh();

        $this->assertSame(ReportExportStatus::Failed, $export->status);
        $this->assertNotNull($export->started_at);
        $this->assertNotNull($export->failed_at);
        $this->assertNull($export->finished_at);
        $this->assertStringContainsString('exists and is not a directory', $export->error_message);
    }

    public function test_it_does_not_generate_report_with_final_status(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Finished,
            'output_path' => $this->temporaryDirectory.DIRECTORY_SEPARATOR.'already-generated.csv',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be generated because its status is final');

        $this->makeService()->generate(
            export: $export,
            outputDirectory: $this->temporaryDirectory,
        );
    }

    private function makeService(): GenerateGatewayLogReportService
    {
        return new GenerateGatewayLogReportService(
            factory: new GatewayLogReportFactory,
            writer: new CsvReportWriter,
        );
    }

    private function createImport(): LogImport
    {
        return LogImport::query()->create([
            'file_path' => 'storage/app/logs/logs.txt',
            'file_hash' => str_repeat('a', 64),
            'status' => LogImportStatus::Finished,
            'current_offset' => 100,
            'last_line_number' => 10,
            'total_lines_processed' => 10,
            'total_lines_failed' => 0,
        ]);
    }

    private function createGatewayLog(
        LogImport $import,
        int $lineNumber,
        string $consumerId,
        string $serviceName,
        int $latencyRequest = 100,
        int $latencyProxy = 80,
        int $latencyGateway = 20,
    ): ApiGatewayLog {
        $date = CarbonImmutable::parse('2026-05-28 12:00:00', 'UTC');

        return ApiGatewayLog::query()->create([
            'log_import_id' => $import->id,
            'event_hash' => hash('sha256', 'test-event-'.$lineNumber),
            'line_number' => $lineNumber,
            'byte_offset' => $lineNumber * 100,
            'consumer_id' => $consumerId,
            'service_id' => $serviceName.'-id',
            'service_name' => $serviceName,
            'request_method' => 'GET',
            'request_uri' => '/test',
            'response_status' => 200,
            'latency_request' => $latencyRequest,
            'latency_proxy' => $latencyProxy,
            'latency_gateway' => $latencyGateway,
            'started_at' => $date,
            'created_at' => $date,
            'processed_at' => $date,
            'raw_payload' => [
                'service' => [
                    'name' => $serviceName,
                ],
            ],
            'updated_at' => $date,
        ]);
    }

    /**
     * @return list<list<string|null>>
     */
    private function readCsv(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        $this->assertIsArray($lines);

        return array_map(
            static fn (string $line): array => str_getcsv($line),
            $lines,
        );
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            if (is_file($directory)) {
                unlink($directory);
            }

            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
