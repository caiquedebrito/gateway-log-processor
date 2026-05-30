<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\GatewayLog\Reports\GatewayLogReportFactory;
use App\Application\GatewayLog\Services\CsvReportWriter;
use App\Application\GatewayLog\Services\GenerateGatewayLogReportService;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Domain\GatewayLog\Enums\ReportDateField;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GatewayLogReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'gateway-log-report-filters-'
            .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_requests_by_consumer_report_filters_by_started_at(): void
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

        $export = $this->createReportExport(
            type: ReportType::RequestsByConsumer,
            filters: new ReportFiltersData(
                dateField: ReportDateField::StartedAt,
                dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
                dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
            ),
        );

        $generated = $this->makeService()->generate($export, $this->temporaryDirectory);

        $this->assertSame([
            ['consumer_id', 'total'],
            ['consumer-a', '1'],
        ], $this->readCsv($generated->output_path));
    }

    public function test_requests_by_consumer_report_filters_by_processed_at(): void
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

        $export = $this->createReportExport(
            type: ReportType::RequestsByConsumer,
            filters: new ReportFiltersData(
                dateField: ReportDateField::ProcessedAt,
                dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
                dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
            ),
        );

        $generated = $this->makeService()->generate($export, $this->temporaryDirectory);

        $this->assertSame([
            ['consumer_id', 'total'],
            ['consumer-b', '1'],
        ], $this->readCsv($generated->output_path));
    }

    public function test_requests_by_service_report_filters_by_started_at(): void
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

        $export = $this->createReportExport(
            type: ReportType::RequestsByService,
            filters: new ReportFiltersData(
                dateField: ReportDateField::StartedAt,
                dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
                dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
            ),
        );

        $generated = $this->makeService()->generate($export, $this->temporaryDirectory);

        $this->assertSame([
            ['service_name', 'total'],
            ['catalog-service', '1'],
        ], $this->readCsv($generated->output_path));
    }

    public function test_average_latency_by_service_report_filters_by_processed_at(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog(
            import: $import,
            lineNumber: 1,
            consumerId: 'consumer-a',
            serviceName: 'catalog-service',
            startedAt: '2026-05-10 10:00:00',
            processedAt: '2026-05-10 10:00:00',
            latencyRequest: 100,
            latencyProxy: 70,
            latencyGateway: 10,
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 2,
            consumerId: 'consumer-b',
            serviceName: 'catalog-service',
            startedAt: '2026-05-11 10:00:00',
            processedAt: '2026-05-11 10:00:00',
            latencyRequest: 300,
            latencyProxy: 130,
            latencyGateway: 30,
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 3,
            consumerId: 'consumer-c',
            serviceName: 'billing-service',
            startedAt: '2026-04-10 10:00:00',
            processedAt: '2026-04-10 10:00:00',
            latencyRequest: 900,
            latencyProxy: 900,
            latencyGateway: 90,
        );

        $export = $this->createReportExport(
            type: ReportType::AverageLatencyByService,
            filters: new ReportFiltersData(
                dateField: ReportDateField::ProcessedAt,
                dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
                dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
            ),
        );

        $generated = $this->makeService()->generate($export, $this->temporaryDirectory);

        $this->assertSame([
            [
                'service_name',
                'avg_latency_request',
                'avg_latency_proxy',
                'avg_latency_gateway',
            ],
            [
                'catalog-service',
                '200.00',
                '100.00',
                '20.00',
            ],
        ], $this->readCsv($generated->output_path));
    }

    public function test_report_without_filters_keeps_current_behavior(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog(
            import: $import,
            lineNumber: 1,
            consumerId: 'consumer-a',
            serviceName: 'catalog-service',
            startedAt: '2026-05-10 10:00:00',
            processedAt: '2026-05-10 10:00:00',
        );

        $this->createGatewayLog(
            import: $import,
            lineNumber: 2,
            consumerId: 'consumer-b',
            serviceName: 'billing-service',
            startedAt: '2026-04-10 10:00:00',
            processedAt: '2026-04-10 10:00:00',
        );

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
            'filters' => null,
        ]);

        $generated = $this->makeService()->generate($export, $this->temporaryDirectory);

        $this->assertSame([
            ['consumer_id', 'total'],
            ['consumer-a', '1'],
            ['consumer-b', '1'],
        ], $this->readCsv($generated->output_path));
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
            'file_hash' => hash('sha256', uniqid('import-', true)),
            'status' => LogImportStatus::Finished,
            'current_offset' => 100,
            'last_line_number' => 10,
            'total_lines_processed' => 10,
            'total_lines_failed' => 0,
        ]);
    }

    private function createReportExport(
        ReportType $type,
        ReportFiltersData $filters,
    ): ReportExport {
        return ReportExport::query()->create([
            'type' => $type,
            'status' => ReportExportStatus::Queued,
            'filters' => $filters->toDatabaseArray(),
        ]);
    }

    private function createGatewayLog(
        LogImport $import,
        int $lineNumber,
        string $consumerId,
        string $serviceName,
        string $startedAt,
        string $processedAt,
        int $latencyRequest = 100,
        int $latencyProxy = 80,
        int $latencyGateway = 20,
    ): ApiGatewayLog {
        $startedAtDate = CarbonImmutable::parse($startedAt, 'UTC');
        $processedAtDate = CarbonImmutable::parse($processedAt, 'UTC');

        return ApiGatewayLog::query()->create([
            'log_import_id' => $import->id,
            'event_hash' => hash('sha256', 'report-filter-event-'.$lineNumber),
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
