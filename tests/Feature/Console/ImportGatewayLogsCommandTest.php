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
        ])->assertSuccessful();

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

    public function test_it_accepts_a_relative_file_path(): void
    {
        Bus::fake();

        $relativePath = 'storage/app/testing-gateway-logs.txt';
        $absolutePath = base_path($relativePath);

        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, '{"started_at":1433209822425}'.PHP_EOL);

        try {
            $this->artisan('gateway-log:import', [
                'file' => $relativePath,
                '--chunk' => 100,
            ])->assertSuccessful();

            $import = LogImport::query()->firstOrFail();

            $this->assertSame(realpath($absolutePath), $import->file_path);

            Bus::assertDispatched(ProcessGatewayLogImportJob::class);
        } finally {
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }
        }
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

    private function createTemporaryLogFile(string $content): string
    {
        $filePath = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'logs.txt';

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
