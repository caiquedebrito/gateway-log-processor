<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Jobs\ProcessGatewayLogImportJob;
use App\Models\LogImport;
use Illuminate\Console\Command;
use RuntimeException;

final class ImportGatewayLogsCommand extends Command
{
    protected $signature = 'gateway-log:import
        {file : Path to the NDJSON log file}
        {--chunk=1000 : Number of lines processed per queued job}';

    protected $description = 'Queue an incremental API Gateway log import.';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize <= 0) {
            $this->error('The chunk size must be greater than zero.');

            return self::FAILURE;
        }

        $filePath = $this->resolveFilePath((string) $this->argument('file'));

        if (! is_file($filePath)) {
            $this->error("Log file [{$filePath}] does not exist.");

            return self::FAILURE;
        }

        if (! is_readable($filePath)) {
            $this->error("Log file [{$filePath}] is not readable.");

            return self::FAILURE;
        }

        $fileHash = hash_file('sha256', $filePath);

        if ($fileHash === false) {
            throw new RuntimeException("Could not calculate hash for file [{$filePath}].");
        }

        $import = LogImport::query()->create([
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'status' => LogImportStatus::Queued,
            'current_offset' => 0,
            'last_line_number' => 0,
            'total_lines_processed' => 0,
            'total_lines_failed' => 0,
        ]);

        ProcessGatewayLogImportJob::dispatch(
            logImportId: (int) $import->id,
            chunkSize: $chunkSize,
        );

        $this->info("Gateway log import [{$import->id}] queued successfully.");
        $this->line("File: {$filePath}");
        $this->line("Chunk size: {$chunkSize}");

        return self::SUCCESS;
    }

    private function resolveFilePath(string $filePath): string
    {
        if (is_file($filePath)) {
            return realpath($filePath) ?: $filePath;
        }

        $basePathFile = base_path($filePath);

        if (is_file($basePathFile)) {
            return realpath($basePathFile) ?: $basePathFile;
        }

        return $filePath;
    }
}
