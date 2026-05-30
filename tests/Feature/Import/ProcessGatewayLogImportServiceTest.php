<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\GatewayLog\Services\GatewayLogEventHashGenerator;
use App\Application\GatewayLog\Services\GatewayLogParser;
use App\Application\GatewayLog\Services\NdjsonLogFileReader;
use App\Application\GatewayLog\Services\ProcessGatewayLogImportService;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use App\Models\LogImportError;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

final class ProcessGatewayLogImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'gateway-log-import-'
            .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_imports_a_valid_gateway_log_line(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-05-28 19:30:00', 'UTC'));

        $filePath = $this->createTemporaryLogFile(
            $this->validLogLine(
                consumerId: 'consumer-1',
                serviceId: 'service-1',
                serviceName: 'catalog-service',
                method: 'GET',
                uri: '/products',
                status: 200,
                requestLatency: 120,
                proxyLatency: 90,
                gatewayLatency: 30,
            ).PHP_EOL
        );

        $import = $this->createImport($filePath);

        $progress = $this->makeService()->processChunk($import, chunkSize: 100);

        $this->assertSame(1, ApiGatewayLog::query()->count());
        $this->assertSame(0, LogImportError::query()->count());

        $log = ApiGatewayLog::query()->firstOrFail();

        $this->assertSame($import->id, $log->log_import_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $log->event_hash);
        $this->assertSame(1, $log->line_number);
        $this->assertSame(0, $log->byte_offset);
        $this->assertSame('consumer-1', $log->consumer_id);
        $this->assertSame('service-1', $log->service_id);
        $this->assertSame('catalog-service', $log->service_name);
        $this->assertSame('GET', $log->request_method);
        $this->assertSame('/products', $log->request_uri);
        $this->assertSame(200, $log->response_status);
        $this->assertSame(120, $log->latency_request);
        $this->assertSame(90, $log->latency_proxy);
        $this->assertSame(30, $log->latency_gateway);

        $this->assertSame('2015-06-02 01:50:22', $log->started_at->toDateTimeString());
        $this->assertSame('2015-06-02 01:50:22', $log->created_at->toDateTimeString());
        $this->assertSame('2026-05-28 19:30:00', $log->processed_at->toDateTimeString());

        $import->refresh();

        $this->assertSame(LogImportStatus::Finished, $import->status);
        $this->assertSame(1, $import->total_lines_processed);
        $this->assertSame(0, $import->total_lines_failed);
        $this->assertSame(1, $import->last_line_number);
        $this->assertNotNull($import->finished_at);

        $this->assertTrue($progress->reachedEndOfFile);
        $this->assertSame(1, $progress->totalLinesProcessed);
        $this->assertSame(0, $progress->totalLinesFailed);
    }

    public function test_it_registers_invalid_lines_without_stopping_the_import(): void
    {
        $invalidLine = '{"started_at":';

        $filePath = $this->createTemporaryLogFile(
            $invalidLine.PHP_EOL
            .$this->validLogLine(
                consumerId: 'consumer-2',
                serviceId: 'service-2',
                serviceName: 'billing-service',
            ).PHP_EOL
        );

        $import = $this->createImport($filePath);

        $progress = $this->makeService()->processChunk($import, chunkSize: 100);

        $this->assertSame(1, ApiGatewayLog::query()->count());
        $this->assertSame(1, LogImportError::query()->count());

        $error = LogImportError::query()->firstOrFail();

        $this->assertSame($import->id, $error->log_import_id);
        $this->assertSame(1, $error->line_number);
        $this->assertSame(0, $error->byte_offset);
        $this->assertNotEmpty($error->error_message);
        $this->assertSame($invalidLine.PHP_EOL, $error->raw_line);

        $log = ApiGatewayLog::query()->firstOrFail();

        $this->assertSame(2, $log->line_number);
        $this->assertSame('consumer-2', $log->consumer_id);
        $this->assertSame('billing-service', $log->service_name);

        $import->refresh();

        $this->assertSame(LogImportStatus::Finished, $import->status);
        $this->assertSame(1, $import->total_lines_processed);
        $this->assertSame(1, $import->total_lines_failed);
        $this->assertSame(2, $import->last_line_number);

        $this->assertTrue($progress->reachedEndOfFile);
        $this->assertSame(1, $progress->totalLinesProcessed);
        $this->assertSame(1, $progress->totalLinesFailed);
    }

    public function test_it_processes_only_the_requested_chunk_and_keeps_import_processing(): void
    {
        $line1 = $this->validLogLine(
            consumerId: 'consumer-1',
            serviceId: 'service-1',
            serviceName: 'catalog-service',
            uri: '/products',
        ).PHP_EOL;

        $line2 = $this->validLogLine(
            consumerId: 'consumer-2',
            serviceId: 'service-2',
            serviceName: 'billing-service',
            uri: '/payments',
        ).PHP_EOL;

        $filePath = $this->createTemporaryLogFile($line1.$line2);

        $import = $this->createImport($filePath);

        $progress = $this->makeService()->processChunk($import, chunkSize: 1);

        $this->assertSame(1, ApiGatewayLog::query()->count());
        $this->assertSame(0, LogImportError::query()->count());

        $import->refresh();

        $this->assertSame(LogImportStatus::Processing, $import->status);
        $this->assertSame(strlen($line1), $import->current_offset);
        $this->assertSame(1, $import->last_line_number);
        $this->assertSame(1, $import->total_lines_processed);
        $this->assertSame(0, $import->total_lines_failed);
        $this->assertNull($import->finished_at);

        $this->assertFalse($progress->reachedEndOfFile);
        $this->assertSame(strlen($line1), $progress->currentOffset);
        $this->assertSame(1, $progress->lastLineNumber);
    }

    public function test_it_continues_import_from_the_previous_offset(): void
    {
        $line1 = $this->validLogLine(
            consumerId: 'consumer-1',
            serviceId: 'service-1',
            serviceName: 'catalog-service',
            uri: '/products',
        ).PHP_EOL;

        $line2 = $this->validLogLine(
            consumerId: 'consumer-2',
            serviceId: 'service-2',
            serviceName: 'billing-service',
            uri: '/payments',
        ).PHP_EOL;

        $filePath = $this->createTemporaryLogFile($line1.$line2);

        $import = $this->createImport($filePath);

        $service = $this->makeService();

        $firstProgress = $service->processChunk($import, chunkSize: 1);

        $this->assertFalse($firstProgress->reachedEndOfFile);

        $import->refresh();

        $secondProgress = $service->processChunk($import, chunkSize: 1);

        $this->assertTrue($secondProgress->reachedEndOfFile);

        $this->assertSame(2, ApiGatewayLog::query()->count());

        $logs = ApiGatewayLog::query()
            ->orderBy('line_number')
            ->get();

        $this->assertSame(1, $logs[0]->line_number);
        $this->assertSame('consumer-1', $logs[0]->consumer_id);

        $this->assertSame(2, $logs[1]->line_number);
        $this->assertSame('consumer-2', $logs[1]->consumer_id);

        $import->refresh();

        $this->assertSame(LogImportStatus::Finished, $import->status);
        $this->assertSame(strlen($line1.$line2), $import->current_offset);
        $this->assertSame(2, $import->last_line_number);
        $this->assertSame(2, $import->total_lines_processed);
        $this->assertSame(0, $import->total_lines_failed);
        $this->assertNotNull($import->finished_at);
    }

    public function test_it_ignores_duplicate_events_inside_same_chunk(): void
    {
        $line = $this->validLogLine(
            consumerId: 'consumer-1',
            serviceId: 'service-1',
            serviceName: 'catalog-service',
            uri: '/products',
        ).PHP_EOL;

        $filePath = $this->createTemporaryLogFile($line.$line);

        $import = $this->createImport($filePath);

        $progress = $this->makeService()->processChunk($import, chunkSize: 100);

        $this->assertSame(1, ApiGatewayLog::query()->count());
        $this->assertSame(0, LogImportError::query()->count());

        $import->refresh();

        $this->assertSame(LogImportStatus::Finished, $import->status);
        $this->assertSame(2, $import->total_lines_processed);
        $this->assertSame(0, $import->total_lines_failed);
        $this->assertSame(2, $import->last_line_number);

        $this->assertTrue($progress->reachedEndOfFile);
        $this->assertSame(2, $progress->totalLinesProcessed);
    }

    public function test_it_ignores_event_already_persisted_from_previous_import(): void
    {
        $line = $this->validLogLine(
            consumerId: 'consumer-1',
            serviceId: 'service-1',
            serviceName: 'catalog-service',
            uri: '/products',
        ).PHP_EOL;

        $firstFilePath = $this->createTemporaryLogFile($line);

        $firstImport = $this->createImport(
            filePath: $firstFilePath,
            fileHash: hash('sha256', 'first-import'),
        );

        $service = $this->makeService();

        $service->processChunk($firstImport, chunkSize: 100);

        $this->assertSame(1, ApiGatewayLog::query()->count());

        $secondFilePath = $this->createTemporaryLogFileInDifferentName(
            filename: 'logs-second.txt',
            content: $line,
        );

        $secondImport = $this->createImport(
            filePath: $secondFilePath,
            fileHash: hash('sha256', 'second-import'),
        );

        $progress = $service->processChunk($secondImport, chunkSize: 100);

        $this->assertSame(1, ApiGatewayLog::query()->count());
        $this->assertSame(0, LogImportError::query()->count());

        $secondImport->refresh();

        $this->assertSame(LogImportStatus::Finished, $secondImport->status);
        $this->assertSame(1, $secondImport->total_lines_processed);
        $this->assertSame(0, $secondImport->total_lines_failed);
        $this->assertSame(1, $secondImport->last_line_number);

        $this->assertTrue($progress->reachedEndOfFile);
    }

    public function test_it_imports_only_new_events_from_accumulated_file(): void
    {
        $lineA = $this->validLogLine(
            consumerId: 'consumer-a',
            serviceId: 'service-a',
            serviceName: 'catalog-service',
            uri: '/products/1',
        ).PHP_EOL;

        $lineB = $this->validLogLine(
            consumerId: 'consumer-b',
            serviceId: 'service-b',
            serviceName: 'billing-service',
            uri: '/payments/1',
        ).PHP_EOL;

        $firstFilePath = $this->createTemporaryLogFile($lineA.$lineB);

        $firstImport = $this->createImport(
            filePath: $firstFilePath,
            fileHash: hash('sha256', 'first-accumulated-import'),
        );

        $service = $this->makeService();

        $service->processChunk($firstImport, chunkSize: 100);

        $this->assertSame(2, ApiGatewayLog::query()->count());

        $lineC = $this->validLogLine(
            consumerId: 'consumer-c',
            serviceId: 'service-c',
            serviceName: 'orders-service',
            uri: '/orders/1',
        ).PHP_EOL;

        $lineD = $this->validLogLine(
            consumerId: 'consumer-d',
            serviceId: 'service-d',
            serviceName: 'shipping-service',
            uri: '/shipping/1',
        ).PHP_EOL;

        $secondFilePath = $this->createTemporaryLogFileInDifferentName(
            filename: 'logs-accumulated.txt',
            content: $lineA.$lineB.$lineC.$lineD,
        );

        $secondImport = $this->createImport(
            filePath: $secondFilePath,
            fileHash: hash('sha256', 'second-accumulated-import'),
        );

        $service->processChunk($secondImport, chunkSize: 100);

        $this->assertSame(4, ApiGatewayLog::query()->count());

        $secondImport->refresh();

        $this->assertSame(LogImportStatus::Finished, $secondImport->status);
        $this->assertSame(4, $secondImport->total_lines_processed);
        $this->assertSame(0, $secondImport->total_lines_failed);

        $this->assertDatabaseHas('api_gateway_logs', [
            'consumer_id' => 'consumer-a',
            'request_uri' => '/products/1',
        ]);

        $this->assertDatabaseHas('api_gateway_logs', [
            'consumer_id' => 'consumer-b',
            'request_uri' => '/payments/1',
        ]);

        $this->assertDatabaseHas('api_gateway_logs', [
            'consumer_id' => 'consumer-c',
            'request_uri' => '/orders/1',
        ]);

        $this->assertDatabaseHas('api_gateway_logs', [
            'consumer_id' => 'consumer-d',
            'request_uri' => '/shipping/1',
        ]);
    }

    public function test_it_ignores_duplicates_and_still_registers_invalid_lines(): void
    {
        $validLine = $this->validLogLine(
            consumerId: 'consumer-1',
            serviceId: 'service-1',
            serviceName: 'catalog-service',
            uri: '/products',
        ).PHP_EOL;

        $invalidLine = '{"started_at":'.PHP_EOL;

        $filePath = $this->createTemporaryLogFile(
            $validLine.$validLine.$invalidLine
        );

        $import = $this->createImport($filePath);

        $this->makeService()->processChunk($import, chunkSize: 100);

        $this->assertSame(1, ApiGatewayLog::query()->count());
        $this->assertSame(1, LogImportError::query()->count());

        $import->refresh();

        $this->assertSame(LogImportStatus::Finished, $import->status);
        $this->assertSame(2, $import->total_lines_processed);
        $this->assertSame(1, $import->total_lines_failed);
        $this->assertSame(3, $import->last_line_number);
    }

    public function test_it_marks_import_as_failed_when_a_critical_error_happens(): void
    {
        $import = LogImport::query()->create([
            'file_path' => $this->temporaryDirectory.DIRECTORY_SEPARATOR.'missing-logs.txt',
            'file_hash' => hash('sha256', 'missing-file-import'),
            'status' => LogImportStatus::Queued,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);

        try {
            $this->makeService()->processChunk($import, chunkSize: 100);

            $this->fail('Expected exception was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not exist', $exception->getMessage());
        }

        $import->refresh();

        $this->assertSame(LogImportStatus::Failed, $import->status);
        $this->assertNotNull($import->failed_at);
        $this->assertStringContainsString('does not exist', $import->error_message);
    }

    public function test_it_does_not_process_import_with_final_status(): void
    {
        $filePath = $this->createTemporaryLogFile(
            $this->validLogLine(
                consumerId: 'consumer-1',
                serviceId: 'service-1',
                serviceName: 'catalog-service',
            ).PHP_EOL
        );

        $import = LogImport::query()->create([
            'file_path' => $filePath,
            'file_hash' => hash('sha256', 'finished-import'),
            'status' => LogImportStatus::Finished,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be processed because its status is final');

        $this->makeService()->processChunk($import, chunkSize: 100);
    }

    private function makeService(): ProcessGatewayLogImportService
    {
        return new ProcessGatewayLogImportService(
            reader: new NdjsonLogFileReader,
            parser: new GatewayLogParser,
            eventHashGenerator: new GatewayLogEventHashGenerator,
        );
    }

    private function createImport(string $filePath, ?string $fileHash = null): LogImport
    {
        return LogImport::query()->create([
            'file_path' => $filePath,
            'file_hash' => $fileHash ?? hash_file('sha256', $filePath),
            'status' => LogImportStatus::Queued,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);
    }

    private function createTemporaryLogFile(string $content): string
    {
        $filePath = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'logs.txt';

        file_put_contents($filePath, $content);

        return $filePath;
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
        string $method = 'GET',
        string $uri = '/get',
        int $status = 200,
        int $requestLatency = 1921,
        int $proxyLatency = 1430,
        int $gatewayLatency = 9,
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
            'started_at' => 1433209822425,
        ], JSON_THROW_ON_ERROR);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
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
