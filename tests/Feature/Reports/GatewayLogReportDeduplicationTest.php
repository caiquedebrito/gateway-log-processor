<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\GatewayLog\Reports\GatewayLogReportFactory;
use App\Application\GatewayLog\Services\CsvReportWriter;
use App\Application\GatewayLog\Services\GatewayLogEventHashGenerator;
use App\Application\GatewayLog\Services\GatewayLogParser;
use App\Application\GatewayLog\Services\GenerateGatewayLogReportService;
use App\Application\GatewayLog\Services\NdjsonLogFileReader;
use App\Application\GatewayLog\Services\ProcessGatewayLogImportService;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use App\Models\ReportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GatewayLogReportDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'gateway-log-report-dedup-'
            .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_requests_by_consumer_report_does_not_count_duplicated_events_from_accumulated_files(): void
    {
        $this->processAccumulatedFilesScenario();

        $export = $this->createReportExport(ReportType::RequestsByConsumer);

        $generatedExport = $this->makeReportService()->generate(
            export: $export,
            outputDirectory: $this->temporaryDirectory,
        );

        $rows = $this->readCsv($generatedExport->output_path);

        $this->assertSame([
            ['consumer_id', 'total'],
            ['consumer-a', '2'],
            ['consumer-b', '1'],
            ['consumer-c', '1'],
        ], $rows);
    }

    public function test_requests_by_service_report_does_not_count_duplicated_events_from_accumulated_files(): void
    {
        $this->processAccumulatedFilesScenario();

        $export = $this->createReportExport(ReportType::RequestsByService);

        $generatedExport = $this->makeReportService()->generate(
            export: $export,
            outputDirectory: $this->temporaryDirectory,
        );

        $rows = $this->readCsv($generatedExport->output_path);

        $this->assertSame([
            ['service_name', 'total'],
            ['catalog-service', '2'],
            ['billing-service', '1'],
            ['orders-service', '1'],
        ], $rows);
    }

    public function test_average_latency_by_service_report_does_not_use_duplicated_events_from_accumulated_files(): void
    {
        $this->processAccumulatedFilesScenario();

        $export = $this->createReportExport(ReportType::AverageLatencyByService);

        $generatedExport = $this->makeReportService()->generate(
            export: $export,
            outputDirectory: $this->temporaryDirectory,
        );

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
                '90.00',
                '20.00',
            ],
            [
                'orders-service',
                '400.00',
                '300.00',
                '40.00',
            ],
        ], $rows);
    }

    private function processAccumulatedFilesScenario(): void
    {
        $lineA = $this->validLogLine(
            consumerId: 'consumer-a',
            serviceId: 'service-a',
            serviceName: 'catalog-service',
            uri: '/products/1',
            requestLatency: 100,
            proxyLatency: 80,
            gatewayLatency: 10,
        ).PHP_EOL;

        $lineB = $this->validLogLine(
            consumerId: 'consumer-b',
            serviceId: 'service-b',
            serviceName: 'billing-service',
            uri: '/payments/1',
            requestLatency: 300,
            proxyLatency: 200,
            gatewayLatency: 30,
        ).PHP_EOL;

        $firstFilePath = $this->createTemporaryLogFileInDifferentName(
            filename: 'logs-first.txt',
            content: $lineA.$lineB,
        );

        $firstImport = $this->createImport(
            filePath: $firstFilePath,
            fileHash: hash('sha256', 'first-report-dedup-import'),
        );

        $this->makeImportService()->processChunk($firstImport, chunkSize: 100);

        $this->assertSame(2, ApiGatewayLog::query()->count());

        $lineC = $this->validLogLine(
            consumerId: 'consumer-a',
            serviceId: 'service-a',
            serviceName: 'catalog-service',
            uri: '/products/2',
            requestLatency: 200,
            proxyLatency: 100,
            gatewayLatency: 30,
        ).PHP_EOL;

        $lineD = $this->validLogLine(
            consumerId: 'consumer-c',
            serviceId: 'service-c',
            serviceName: 'orders-service',
            uri: '/orders/1',
            requestLatency: 400,
            proxyLatency: 300,
            gatewayLatency: 40,
        ).PHP_EOL;

        $secondFilePath = $this->createTemporaryLogFileInDifferentName(
            filename: 'logs-accumulated.txt',
            content: $lineA.$lineB.$lineC.$lineD,
        );

        $secondImport = $this->createImport(
            filePath: $secondFilePath,
            fileHash: hash('sha256', 'second-report-dedup-import'),
        );

        $this->makeImportService()->processChunk($secondImport, chunkSize: 100);

        $this->assertSame(4, ApiGatewayLog::query()->count());

        $firstImport->refresh();
        $secondImport->refresh();

        $this->assertSame(LogImportStatus::Finished, $firstImport->status);
        $this->assertSame(LogImportStatus::Finished, $secondImport->status);

        $this->assertSame(2, $firstImport->total_lines_processed);
        $this->assertSame(4, $secondImport->total_lines_processed);
    }

    private function makeImportService(): ProcessGatewayLogImportService
    {
        return new ProcessGatewayLogImportService(
            reader: new NdjsonLogFileReader,
            parser: new GatewayLogParser,
            eventHashGenerator: new GatewayLogEventHashGenerator,
        );
    }

    private function makeReportService(): GenerateGatewayLogReportService
    {
        return new GenerateGatewayLogReportService(
            factory: new GatewayLogReportFactory,
            writer: new CsvReportWriter,
        );
    }

    private function createReportExport(ReportType $type): ReportExport
    {
        return ReportExport::query()->create([
            'type' => $type,
            'status' => ReportExportStatus::Queued,
        ]);
    }

    private function createImport(string $filePath, string $fileHash): LogImport
    {
        return LogImport::query()->create([
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'status' => LogImportStatus::Queued,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);
    }

    private function createTemporaryLogFileInDifferentName(string $filename, string $content): string
    {
        $filePath = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .$filename;

        file_put_contents($filePath, $content);

        return $filePath;
    }

    private function validLogLine(
        string $consumerId,
        string $serviceId,
        string $serviceName,
        string $uri,
        int $requestLatency,
        int $proxyLatency,
        int $gatewayLatency,
        string $method = 'GET',
        int $status = 200,
    ): string {
        return json_encode([
            'request' => [
                'method' => $method,
                'uri' => $uri,
                'querystring' => [],
            ],
            'response' => [
                'status' => $status,
            ],
            'authenticated_entity' => [
                'consumer_id' => $consumerId,
            ],
            'service' => [
                'id' => $serviceId,
                'name' => $serviceName,
            ],
            'latencies' => [
                'request' => $requestLatency,
                'proxy' => $proxyLatency,
                'gateway' => $gatewayLatency,
            ],
            'client_ip' => '127.0.0.1',
            'started_at' => 1433209822425 + crc32($consumerId.$serviceId.$uri),
        ], JSON_THROW_ON_ERROR);
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
