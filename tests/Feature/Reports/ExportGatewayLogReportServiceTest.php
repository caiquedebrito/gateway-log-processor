<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\GatewayLog\Reports\GatewayLogReportFactory;
use App\Application\GatewayLog\Services\CsvReportWriter;
use App\Application\GatewayLog\Services\ExportGatewayLogReportService;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExportGatewayLogReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
          .DIRECTORY_SEPARATOR
          .'gateway-log-reports-'
          .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_generates_requests_by_consumer_csv_report(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog($import, consumerId: 'consumer-a', serviceName: 'catalog-service');
        $this->createGatewayLog($import, consumerId: 'consumer-a', serviceName: 'billing-service');
        $this->createGatewayLog($import, consumerId: 'consumer-b', serviceName: 'catalog-service');

        $export = $this->makeService()->export(
            type: ReportType::RequestsByConsumer,
            outputDirectory: $this->temporaryDirectory,
        );

        $this->assertSame(ReportType::RequestsByConsumer, $export->type);
        $this->assertSame(ReportExportStatus::Finished, $export->status);
        $this->assertNotNull($export->started_at);
        $this->assertNotNull($export->finished_at);
        $this->assertFileExists($export->output_path);

        $rows = $this->readCsv($export->output_path);

        $this->assertSame([
            ['consumer_id', 'total'],
            ['consumer-a', '2'],
            ['consumer-b', '1'],
        ], $rows);
    }

    public function test_it_generates_requests_by_service_csv_report(): void
    {
        $import = $this->createImport();

        $this->createGatewayLog($import, consumerId: 'consumer-a', serviceName: 'catalog-service');
        $this->createGatewayLog($import, consumerId: 'consumer-b', serviceName: 'catalog-service');
        $this->createGatewayLog($import, consumerId: 'consumer-c', serviceName: 'billing-service');

        $export = $this->makeService()->export(
            type: ReportType::RequestsByService,
            outputDirectory: $this->temporaryDirectory,
        );

        $this->assertSame(ReportType::RequestsByService, $export->type);
        $this->assertSame(ReportExportStatus::Finished, $export->status);
        $this->assertFileExists($export->output_path);

        $rows = $this->readCsv($export->output_path);

        $this->assertSame([
            ['service_name', 'total'],
            ['catalog-service', '2'],
            ['billing-service', '1'],
        ], $rows);
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
        string $consumerId,
        string $serviceName,
        int $latencyRequest = 100,
        int $latencyProxy = 80,
        int $latencyGateway = 20,
    ): ApiGatewayLog {
        $date = CarbonImmutable::parse('2026-05-28 12:00:00', 'UTC');

        return ApiGatewayLog::query()->create([
            'log_import_id' => $import->id,
            'line_number' => random_int(1, 999999),
            'byte_offset' => random_int(0, 999999),
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

    private function makeService(): ExportGatewayLogReportService
    {
        return new ExportGatewayLogReportService(
            factory: new GatewayLogReportFactory,
            writer: new CsvReportWriter,
        );
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
}
