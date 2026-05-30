<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\GatewayLog\Reports\GatewayLogReportFactory;
use App\Application\GatewayLog\Services\CsvReportWriter;
use App\Application\GatewayLog\Services\GenerateGatewayLogReportService;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Domain\GatewayLog\Enums\ReportDateField;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Jobs\ExportGatewayLogReportJob;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

final class ExportGatewayLogReportJobTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'gateway-log-report-job-'
            .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_is_configured_to_reports_queue(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
        ]);

        $job = new ExportGatewayLogReportJob(
            reportExportId: $export->id,
            outputDirectory: $this->temporaryDirectory,
        );

        $this->assertSame('reports', $job->queue);
    }

    public function test_it_can_be_dispatched_on_reports_queue(): void
    {
        Bus::fake();

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
        ]);

        ExportGatewayLogReportJob::dispatch(
            reportExportId: $export->id,
            outputDirectory: $this->temporaryDirectory,
        );

        Bus::assertDispatched(
            ExportGatewayLogReportJob::class,
            function (ExportGatewayLogReportJob $job) use ($export): bool {
                return $job->reportExportId === $export->id
                    && $job->queue === 'reports';
            }
        );
    }

    public function test_it_generates_csv_report_from_job(): void
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

        $job = new ExportGatewayLogReportJob(
            reportExportId: $export->id,
            outputDirectory: $this->temporaryDirectory,
        );

        $job->handle($this->makeService());

        $export->refresh();

        $this->assertSame(ReportExportStatus::Finished, $export->status);
        $this->assertNotNull($export->started_at);
        $this->assertNotNull($export->finished_at);
        $this->assertNull($export->failed_at);
        $this->assertNull($export->error_message);
        $this->assertFileExists($export->output_path);

        $rows = $this->readCsv($export->output_path);

        $this->assertSame([
            ['consumer_id', 'total'],
            ['consumer-a', '2'],
            ['consumer-b', '1'],
        ], $rows);
    }

    public function test_it_ignores_report_export_with_final_status(): void
    {
        $outputPath = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'already-generated.csv';

        file_put_contents($outputPath, 'consumer_id,total'.PHP_EOL);

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Finished,
            'output_path' => $outputPath,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $job = new ExportGatewayLogReportJob(
            reportExportId: $export->id,
            outputDirectory: $this->temporaryDirectory,
        );

        $job->handle($this->makeService());

        $export->refresh();

        $this->assertSame(ReportExportStatus::Finished, $export->status);
        $this->assertSame($outputPath, $export->output_path);
        $this->assertSame('consumer_id,total'.PHP_EOL, file_get_contents($outputPath));
    }

    public function test_it_marks_report_as_failed_when_generation_fails(): void
    {
        $invalidOutputDirectory = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'not-a-directory';

        file_put_contents($invalidOutputDirectory, 'content');

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
        ]);

        $job = new ExportGatewayLogReportJob(
            reportExportId: $export->id,
            outputDirectory: $invalidOutputDirectory,
        );

        try {
            $job->handle($this->makeService());

            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'exists and is not a directory',
                $exception->getMessage()
            );
        }

        $export->refresh();

        $this->assertSame(ReportExportStatus::Failed, $export->status);
        $this->assertNotNull($export->started_at);
        $this->assertNotNull($export->failed_at);
        $this->assertNull($export->finished_at);
        $this->assertStringContainsString(
            'exists and is not a directory',
            $export->error_message
        );
    }

    public function test_it_generates_csv_using_persisted_report_filters(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog(
            import: $import,
            lineNumber: 1,
            consumerId: 'consumer-a',
            serviceName: 'catalog-service',
            startedAt: '2026-05-10 10:00:00',
            processedAt: '2026-06-01 10:00:00',
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 2,
            consumerId: 'consumer-b',
            serviceName: 'billing-service',
            startedAt: '2026-04-10 10:00:00',
            processedAt: '2026-05-10 10:00:00',
        );

        $filters = new ReportFiltersData(
            dateField: ReportDateField::ProcessedAt,
            dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
            dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
        );

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
            'filters' => $filters->toDatabaseArray(),
        ]);

        $job = new ExportGatewayLogReportJob(
            reportExportId: $export->id,
            outputDirectory: $this->temporaryDirectory,
        );

        $job->handle($this->makeService());

        $export->refresh();

        $this->assertSame(ReportExportStatus::Finished, $export->status);
        $this->assertFileExists($export->output_path);

        $this->assertSame([
            ['consumer_id', 'total'],
            ['consumer-b', '1'],
        ], $this->readCsv($export->output_path));
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
        ?string $startedAt = null,
        ?string $processedAt = null,
    ): ApiGatewayLog {
        $startedAtDate = CarbonImmutable::parse(
            $startedAt ?? '2026-05-28 12:00:00',
            'UTC',
        );

        $processedAtDate = CarbonImmutable::parse(
            $processedAt ?? '2026-05-28 12:00:00',
            'UTC',
        );

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
            'started_at' => $startedAtDate,
            'created_at' => $startedAtDate,
            'processed_at' => $processedAtDate,
            'raw_payload' => [
                'service' => [
                    'name' => $serviceName,
                ],
            ],
            'updated_at' => $processedAtDate,
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
