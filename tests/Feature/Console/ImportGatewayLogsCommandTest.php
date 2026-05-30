<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Jobs\ProcessGatewayLogImportJob;
use App\Models\LogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ImportGatewayLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'gateway-log-command-'
            .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_creates_a_log_import_and_dispatches_the_import_job(): void
    {
        Bus::fake();

        $filePath = $this->createTemporaryLogFile(
            '{"started_at":1433209822425}'.PHP_EOL
        );

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => 500,
        ])
            ->expectsOutputToContain('queued successfully')
            ->assertSuccessful();

        $this->assertSame(1, LogImport::query()->count());

        $import = LogImport::query()->firstOrFail();

        $this->assertSame(realpath($filePath), $import->file_path);
        $this->assertSame(hash_file('sha256', $filePath), $import->file_hash);
        $this->assertSame(LogImportStatus::Queued, $import->status);
        $this->assertSame(0, $import->current_offset);
        $this->assertSame(0, $import->last_line_number);
        $this->assertSame(0, $import->total_lines_processed);
        $this->assertSame(0, $import->total_lines_failed);
        $this->assertNull($import->started_at);
        $this->assertNull($import->finished_at);
        $this->assertNull($import->failed_at);

        Bus::assertDispatched(
            ProcessGatewayLogImportJob::class,
            function (ProcessGatewayLogImportJob $job) use ($import): bool {
                return $job->logImportId === $import->id
                    && $job->chunkSize === 500;
            }
        );
    }

    public function test_it_does_not_create_duplicate_import_when_same_file_is_already_finished(): void
    {
        Bus::fake();

        $filePath = $this->createTemporaryLogFile(
            '{"started_at":1433209822425}'.PHP_EOL
        );

        $existingImport = $this->createExistingImport(
            filePath: $filePath,
            status: LogImportStatus::Finished,
        );

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain("Gateway log import [{$existingImport->id}] was already processed.")
            ->expectsOutputToContain('No new job was dispatched.')
            ->assertSuccessful();

        $this->assertSame(1, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_does_not_dispatch_duplicate_job_when_same_file_is_already_queued(): void
    {
        Bus::fake();

        $filePath = $this->createTemporaryLogFile(
            '{"started_at":1433209822425}'.PHP_EOL
        );

        $existingImport = $this->createExistingImport(
            filePath: $filePath,
            status: LogImportStatus::Queued,
        );

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain("Gateway log import [{$existingImport->id}] is already queued.")
            ->expectsOutputToContain('No new job was dispatched.')
            ->assertSuccessful();

        $this->assertSame(1, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_does_not_dispatch_duplicate_job_when_same_file_is_already_processing(): void
    {
        Bus::fake();

        $filePath = $this->createTemporaryLogFile(
            '{"started_at":1433209822425}'.PHP_EOL
        );

        $existingImport = $this->createExistingImport(
            filePath: $filePath,
            status: LogImportStatus::Processing,
        );

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain("Gateway log import [{$existingImport->id}] is already processing.")
            ->expectsOutputToContain('No new job was dispatched.')
            ->assertSuccessful();

        $this->assertSame(1, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_does_not_reprocess_same_file_when_existing_import_failed(): void
    {
        Bus::fake();

        $filePath = $this->createTemporaryLogFile(
            '{"started_at":1433209822425}'.PHP_EOL
        );

        $existingImport = $this->createExistingImport(
            filePath: $filePath,
            status: LogImportStatus::Failed,
        );

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain("Gateway log import [{$existingImport->id}] already exists with failed status.")
            ->expectsOutputToContain('No new job was dispatched.')
            ->assertSuccessful();

        $this->assertSame(1, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_creates_new_import_for_different_file_hash(): void
    {
        Bus::fake();

        $firstFilePath = $this->createTemporaryLogFileInDifferentName(
            filename: 'logs-first.txt',
            content: '{"started_at":1433209822425}'.PHP_EOL,
        );

        $secondFilePath = $this->createTemporaryLogFileInDifferentName(
            filename: 'logs-second.txt',
            content: '{"started_at":1433209822426}'.PHP_EOL,
        );

        $this->createExistingImport(
            filePath: $firstFilePath,
            status: LogImportStatus::Finished,
        );

        $this->artisan('gateway-log:import', [
            'file' => $secondFilePath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain('queued successfully')
            ->assertSuccessful();

        $this->assertSame(2, LogImport::query()->count());

        $newImport = LogImport::query()
            ->where('file_hash', hash_file('sha256', $secondFilePath))
            ->firstOrFail();

        Bus::assertDispatched(
            ProcessGatewayLogImportJob::class,
            function (ProcessGatewayLogImportJob $job) use ($newImport): bool {
                return $job->logImportId === $newImport->id
                    && $job->chunkSize === 1000;
            }
        );
    }

    public function test_it_fails_when_file_does_not_exist(): void
    {
        Bus::fake();

        $missingFile = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'missing-logs.txt';

        $this->artisan('gateway-log:import', [
            'file' => $missingFile,
            '--chunk' => 100,
        ])->assertFailed();

        $this->assertSame(0, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_fails_when_chunk_size_is_zero(): void
    {
        Bus::fake();

        $filePath = $this->createTemporaryLogFile(
            '{"started_at":1433209822425}'.PHP_EOL
        );

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => 0,
        ])->assertFailed();

        $this->assertSame(0, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_fails_when_chunk_size_is_negative(): void
    {
        Bus::fake();

        $filePath = $this->createTemporaryLogFile(
            '{"started_at":1433209822425}'.PHP_EOL
        );

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => -10,
        ])->assertFailed();

        $this->assertSame(0, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    private function createExistingImport(
        string $filePath,
        LogImportStatus $status,
    ): LogImport {
        return LogImport::query()->create([
            'file_path' => realpath($filePath) ?: $filePath,
            'file_hash' => hash_file('sha256', $filePath),
            'status' => $status,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
            'started_at' => $status === LogImportStatus::Processing ? now() : null,
            'finished_at' => $status === LogImportStatus::Finished ? now() : null,
            'failed_at' => $status === LogImportStatus::Failed ? now() : null,
            'error_message' => $status === LogImportStatus::Failed ? 'Previous import failed.' : null,
        ]);
    }

    private function createTemporaryLogFile(string $content): string
    {
        return $this->createTemporaryLogFileInDifferentName(
            filename: 'logs.txt',
            content: $content,
        );
    }

    private function createTemporaryLogFileInDifferentName(
        string $filename,
        string $content,
    ): string {
        $filePath = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .$filename;

        file_put_contents($filePath, $content);

        return $filePath;
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
