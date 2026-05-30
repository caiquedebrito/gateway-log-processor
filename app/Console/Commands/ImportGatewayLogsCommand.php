<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\GatewayLog\Services\ImportFileResolver;
use App\Domain\GatewayLog\DTO\ResolvedImportFileData;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Domain\GatewayLog\Exceptions\ImportFileResolutionException;
use App\Jobs\ProcessGatewayLogImportJob;
use App\Models\LogImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

final class ImportGatewayLogsCommand extends Command
{
    protected $signature = 'gateway-log:import
        {file : Path to the NDJSON .txt log file or .zip containing a .txt file}
        {--chunk=1000 : Number of lines processed per queued job}';

    protected $description = 'Queue an incremental API Gateway log import.';

    public function handle(ImportFileResolver $fileResolver): int
    {
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize <= 0) {
            $this->error('The chunk size must be greater than zero.');

            return self::FAILURE;
        }

        try {
            $resolvedFile = $fileResolver->resolve((string) $this->argument('file'));
        } catch (ImportFileResolutionException $exception) {
            $this->error('Could not resolve import file.');
            $this->line("Reason: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->writeResolvedFileInfo($resolvedFile);

        $existingImport = LogImport::query()
            ->where('file_hash', $resolvedFile->fileHash)
            ->first();

        if ($existingImport instanceof LogImport) {
            $this->writeExistingImportMessage($existingImport);

            return self::SUCCESS;
        }

        [$import, $wasCreated] = $this->createImportSafely($resolvedFile);

        if (! $wasCreated) {
            $this->writeExistingImportMessage($import);

            return self::SUCCESS;
        }

        ProcessGatewayLogImportJob::dispatch(
            (int) $import->id,
            $chunkSize,
        );

        $this->info("Gateway log import [{$import->id}] queued successfully.");
        $this->line('Queue: default');
        $this->line("Chunk size: {$chunkSize}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: LogImport, 1: bool}
     */
    private function createImportSafely(ResolvedImportFileData $resolvedFile): array
    {
        try {
            $import = LogImport::query()->create([
                'file_path' => $resolvedFile->resolvedPath,
                'file_hash' => $resolvedFile->fileHash,
                'status' => LogImportStatus::Queued,
                'current_offset' => 0,
                'last_line_number' => 0,
                'total_lines_processed' => 0,
                'total_lines_failed' => 0,
            ]);

            return [$import, true];
        } catch (QueryException $exception) {
            $existingImport = LogImport::query()
                ->where('file_hash', $resolvedFile->fileHash)
                ->first();

            if ($existingImport instanceof LogImport) {
                return [$existingImport, false];
            }

            throw $exception;
        }
    }

    private function writeResolvedFileInfo(ResolvedImportFileData $resolvedFile): void
    {
        $this->line("Input file: {$resolvedFile->inputPath}");
        $this->line("Resolved log file: {$resolvedFile->resolvedPath}");
        $this->line("File type: {$resolvedFile->fileType->value}");
        $this->line("File hash: {$resolvedFile->fileHash}");

        if ($resolvedFile->wasExtracted) {
            $this->line("Extracted from: {$resolvedFile->extractedFrom}");
        }
    }

    private function writeExistingImportMessage(LogImport $import): void
    {
        $status = $import->status instanceof LogImportStatus
            ? $import->status
            : LogImportStatus::from((string) $import->status);

        match ($status) {
            LogImportStatus::Queued => $this->warn(
                "Gateway log import [{$import->id}] is already queued."
            ),

            LogImportStatus::Processing => $this->warn(
                "Gateway log import [{$import->id}] is already processing."
            ),

            LogImportStatus::Finished => $this->info(
                "Gateway log import [{$import->id}] was already processed."
            ),

            LogImportStatus::Failed => $this->warn(
                "Gateway log import [{$import->id}] already exists with failed status."
            ),
        };

        $this->line('No new job was dispatched.');
        $this->line("Status: {$status->value}");
        $this->line("File: {$import->file_path}");
    }
}
