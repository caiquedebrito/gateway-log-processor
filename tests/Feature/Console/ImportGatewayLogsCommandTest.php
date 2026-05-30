<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\GatewayLog\Services\ImportFileResolver;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Jobs\ProcessGatewayLogImportJob;
use App\Models\LogImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use ZipArchive;

final class ImportGatewayLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    private string $extractionDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = storage_path(
            'app/testing-import-command-'.bin2hex(random_bytes(8))
        );

        $this->extractionDirectory = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'extracted';

        mkdir($this->temporaryDirectory, 0777, true);

        $this->app->bind(
            ImportFileResolver::class,
            fn (): ImportFileResolver => new ImportFileResolver(
                extractionBaseDirectory: $this->extractionDirectory,
            ),
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_creates_a_log_import_from_absolute_txt_path_and_dispatches_the_import_job(): void
    {
        Bus::fake();

        $content = '{"started_at":1433209822425}'.PHP_EOL;
        $filePath = $this->createTemporaryLogFile('logs.txt', $content);

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => 500,
        ])
            ->expectsOutputToContain('File type: txt')
            ->expectsOutputToContain('queued successfully')
            ->expectsOutputToContain('Queue: default')
            ->assertSuccessful();

        $this->assertSame(1, LogImport::query()->count());

        $import = LogImport::query()->firstOrFail();

        $this->assertSame(realpath($filePath), $import->file_path);
        $this->assertSame(hash('sha256', $content), $import->file_hash);
        $this->assertSame(LogImportStatus::Queued, $import->status);
        $this->assertSame(0, $import->current_offset);
        $this->assertSame(0, $import->last_line_number);
        $this->assertSame(0, $import->total_lines_processed);
        $this->assertSame(0, $import->total_lines_failed);

        Bus::assertDispatched(
            ProcessGatewayLogImportJob::class,
            function (ProcessGatewayLogImportJob $job) use ($import): bool {
                return $job->logImportId === $import->id
                    && $job->chunkSize === 500;
            }
        );
    }

    public function test_it_creates_a_log_import_from_relative_txt_path(): void
    {
        Bus::fake();

        $content = '{"started_at":1433209822425}'.PHP_EOL;
        $filePath = $this->createTemporaryLogFile('relative-logs.txt', $content);

        $relativePath = str_replace(
            base_path().DIRECTORY_SEPARATOR,
            '',
            $filePath,
        );

        $this->artisan('gateway-log:import', [
            'file' => $relativePath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain('File type: txt')
            ->expectsOutputToContain('queued successfully')
            ->assertSuccessful();

        $import = LogImport::query()->firstOrFail();

        $this->assertSame(realpath($filePath), $import->file_path);
        $this->assertSame(hash('sha256', $content), $import->file_hash);

        Bus::assertDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_creates_a_log_import_from_zip_containing_logs_txt(): void
    {
        $this->requireZipExtension();

        Bus::fake();

        $content = '{"started_at":1433209822425}'.PHP_EOL;

        $zipPath = $this->createZipFile('logs.zip', [
            'logs.txt' => $content,
        ]);

        $this->artisan('gateway-log:import', [
            'file' => $zipPath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain('File type: zip')
            ->expectsOutputToContain('Extracted from:')
            ->expectsOutputToContain('queued successfully')
            ->assertSuccessful();

        $import = LogImport::query()->firstOrFail();

        $this->assertSame(hash('sha256', $content), $import->file_hash);
        $this->assertFileExists($import->file_path);
        $this->assertSame($content, file_get_contents($import->file_path));

        Bus::assertDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_creates_a_log_import_from_zip_containing_single_txt_with_different_name(): void
    {
        $this->requireZipExtension();

        Bus::fake();

        $content = '{"started_at":1433209822425}'.PHP_EOL;

        $zipPath = $this->createZipFile('gateway-export.zip', [
            'gateway-export.txt' => $content,
        ]);

        $this->artisan('gateway-log:import', [
            'file' => $zipPath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain('File type: zip')
            ->expectsOutputToContain('queued successfully')
            ->assertSuccessful();

        $import = LogImport::query()->firstOrFail();

        $this->assertSame(hash('sha256', $content), $import->file_hash);
        $this->assertSame($content, file_get_contents($import->file_path));

        Bus::assertDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_does_not_create_duplicate_import_when_txt_and_zip_have_same_txt_content(): void
    {
        $this->requireZipExtension();

        Bus::fake();

        $content = '{"started_at":1433209822425}'.PHP_EOL;

        $txtPath = $this->createTemporaryLogFile('logs.txt', $content);

        $zipPath = $this->createZipFile('logs.zip', [
            'logs.txt' => $content,
        ]);

        $this->artisan('gateway-log:import', [
            'file' => $txtPath,
            '--chunk' => 1000,
        ])->assertSuccessful();

        $this->artisan('gateway-log:import', [
            'file' => $zipPath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain('No new job was dispatched.')
            ->assertSuccessful();

        $this->assertSame(1, LogImport::query()->count());

        Bus::assertDispatched(ProcessGatewayLogImportJob::class, 1);
    }

    public function test_it_does_not_create_duplicate_import_when_same_file_is_already_finished(): void
    {
        Bus::fake();

        $content = '{"started_at":1433209822425}'.PHP_EOL;
        $filePath = $this->createTemporaryLogFile('logs.txt', $content);

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

        $content = '{"started_at":1433209822425}'.PHP_EOL;
        $filePath = $this->createTemporaryLogFile('logs.txt', $content);

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

        $content = '{"started_at":1433209822425}'.PHP_EOL;
        $filePath = $this->createTemporaryLogFile('logs.txt', $content);

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

        $content = '{"started_at":1433209822425}'.PHP_EOL;
        $filePath = $this->createTemporaryLogFile('logs.txt', $content);

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

        $firstFilePath = $this->createTemporaryLogFile(
            filename: 'logs-first.txt',
            content: '{"started_at":1433209822425}'.PHP_EOL,
        );

        $secondFilePath = $this->createTemporaryLogFile(
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

    public function test_it_fails_when_zip_has_no_txt_file(): void
    {
        $this->requireZipExtension();

        Bus::fake();

        $zipPath = $this->createZipFile('without-txt.zip', [
            'data.json' => '{"ok":true}',
        ]);

        $this->artisan('gateway-log:import', [
            'file' => $zipPath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain('Could not resolve import file.')
            ->expectsOutputToContain('does not contain a .txt log file')
            ->assertFailed();

        $this->assertSame(0, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_fails_when_zip_has_multiple_txt_files_without_logs_txt(): void
    {
        $this->requireZipExtension();

        Bus::fake();

        $zipPath = $this->createZipFile('multiple-txt.zip', [
            'first.txt' => 'first',
            'second.txt' => 'second',
        ]);

        $this->artisan('gateway-log:import', [
            'file' => $zipPath,
            '--chunk' => 1000,
        ])
            ->expectsOutputToContain('Could not resolve import file.')
            ->expectsOutputToContain('multiple .txt files')
            ->assertFailed();

        $this->assertSame(0, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
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
        ])
            ->expectsOutputToContain('Could not resolve import file.')
            ->expectsOutputToContain('does not exist')
            ->assertFailed();

        $this->assertSame(0, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_fails_when_chunk_size_is_zero(): void
    {
        Bus::fake();

        $filePath = $this->createTemporaryLogFile(
            filename: 'logs.txt',
            content: '{"started_at":1433209822425}'.PHP_EOL,
        );

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => 0,
        ])
            ->expectsOutputToContain('The chunk size must be greater than zero.')
            ->assertFailed();

        $this->assertSame(0, LogImport::query()->count());

        Bus::assertNotDispatched(ProcessGatewayLogImportJob::class);
    }

    public function test_it_fails_when_chunk_size_is_negative(): void
    {
        Bus::fake();

        $filePath = $this->createTemporaryLogFile(
            filename: 'logs.txt',
            content: '{"started_at":1433209822425}'.PHP_EOL,
        );

        $this->artisan('gateway-log:import', [
            'file' => $filePath,
            '--chunk' => -10,
        ])
            ->expectsOutputToContain('The chunk size must be greater than zero.')
            ->assertFailed();

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

    private function createTemporaryLogFile(string $filename, string $content): string
    {
        $filePath = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .$filename;

        file_put_contents($filePath, $content);

        return $filePath;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function createZipFile(string $filename, array $entries): string
    {
        $this->requireZipExtension();

        $filePath = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .$filename;

        $zip = new ZipArchive;

        $opened = $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $this->assertTrue($opened);

        foreach ($entries as $entryName => $content) {
            $this->assertTrue($zip->addFromString($entryName, $content));
        }

        $this->assertTrue($zip->close());

        return $filePath;
    }

    private function requireZipExtension(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('PHP Zip extension is not installed.');
        }
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
