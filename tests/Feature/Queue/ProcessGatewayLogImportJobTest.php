<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Application\GatewayLog\Services\GatewayLogEventHashGenerator;
use App\Application\GatewayLog\Services\GatewayLogParser;
use App\Application\GatewayLog\Services\NdjsonLogFileReader;
use App\Application\GatewayLog\Services\ProcessGatewayLogImportService;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Jobs\ProcessGatewayLogImportJob;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ProcessGatewayLogImportJobTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
          .DIRECTORY_SEPARATOR
          .'gateway-log-job-'
          .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_processes_one_chunk_and_dispatches_next_job_when_file_has_more_lines(): void
    {
        Bus::fake();

        $line1 = $this->validLogLine('consumer-1', 'service-1', 'catalog-service').PHP_EOL;
        $line2 = $this->validLogLine('consumer-2', 'service-2', 'billing-service').PHP_EOL;

        $filePath = $this->createTemporaryLogFile($line1.$line2);

        $import = $this->createImport($filePath);

        $job = new ProcessGatewayLogImportJob(
            logImportId: $import->id,
            chunkSize: 1,
        );

        $job->handle($this->makeService());

        $this->assertSame(1, ApiGatewayLog::query()->count());

        $import->refresh();

        $this->assertSame(LogImportStatus::Processing, $import->status);
        $this->assertSame(strlen($line1), $import->current_offset);
        $this->assertSame(1, $import->last_line_number);
        $this->assertSame(1, $import->total_lines_processed);

        Bus::assertDispatched(ProcessGatewayLogImportJob::class, function (ProcessGatewayLogImportJob $job) use ($import): bool {
            return $job->logImportId === $import->id
              && $job->chunkSize === 1;
        });
    }

    public function test_it_does_not_dispatch_next_job_when_file_reaches_end(): void
    {
        Bus::fake();

        $line1 = $this->validLogLine('consumer-1', 'service-1', 'catalog-service').PHP_EOL;

        $filePath = $this->createTemporaryLogFile($line1);

        $import = $this->createImport($filePath);

        $job = new ProcessGatewayLogImportJob(
            logImportId: $import->id,
            chunkSize: 100,
        );

        $job->handle($this->makeService());

        $this->assertSame(1, ApiGatewayLog::query()->count());

        $import->refresh();

        $this->assertSame(LogImportStatus::Finished, $import->status);
        $this->assertSame(strlen($line1), $import->current_offset);
        $this->assertSame(1, $import->last_line_number);
        $this->assertSame(1, $import->total_lines_processed);
        $this->assertNotNull($import->finished_at);

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_ignores_imports_with_final_status(): void
    {
        Bus::fake();

        $line1 = $this->validLogLine('consumer-1', 'service-1', 'catalog-service').PHP_EOL;

        $filePath = $this->createTemporaryLogFile($line1);

        $import = LogImport::query()->create([
            'file_path' => $filePath,
            'file_hash' => hash_file('sha256', $filePath),
            'status' => LogImportStatus::Finished,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);

        $job = new ProcessGatewayLogImportJob(
            logImportId: $import->id,
            chunkSize: 100,
        );

        $job->handle($this->makeService());

        $this->assertSame(0, ApiGatewayLog::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    private function makeService(): ProcessGatewayLogImportService
    {
        return new ProcessGatewayLogImportService(
            reader: new NdjsonLogFileReader,
            parser: new GatewayLogParser,
            eventHashGenerator: new GatewayLogEventHashGenerator,

        );
    }

    private function createImport(string $filePath): LogImport
    {
        return LogImport::query()->create([
            'file_path' => $filePath,
            'file_hash' => hash_file('sha256', $filePath),
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

    private function validLogLine(
        string $consumerId,
        string $serviceId,
        string $serviceName,
    ): string {
        return json_encode([
            'request' => [
                'method' => 'GET',
                'uri' => '/get',
            ],
            'response' => [
                'status' => 200,
            ],
            'authenticated_entity' => [
                'consumer_id' => $consumerId,
            ],
            'service' => [
                'id' => $serviceId,
                'name' => $serviceName,
            ],
            'latencies' => [
                'request' => 1921,
                'proxy' => 1430,
                'gateway' => 9,
            ],
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
