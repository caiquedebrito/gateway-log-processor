<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Domain\GatewayLog\DTO\ImportProgressData;
use App\Domain\GatewayLog\Enums\LogImportStatus;
use App\Models\ApiGatewayLog;
use App\Models\LogImport;
use App\Models\LogImportError;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class ProcessGatewayLogImportService
{
    public function __construct(
        private NdjsonLogFileReader $reader,
        private GatewayLogParser $parser,
        private GatewayLogEventHashGenerator $eventHashGenerator,
    ) {}

    public function processChunk(LogImport $import, int $chunkSize = 1000): ImportProgressData
    {
        $this->ensureImportCanBeProcessed($import, $chunkSize);

        $import->markAsProcessing();

        try {
            $chunk = $this->reader->read(
                filePath: $import->file_path,
                fromOffset: (int) $import->current_offset,
                fromLineNumber: (int) $import->last_line_number,
                limit: $chunkSize,
            );

            $processedAt = now();

            $logRows = [];
            $errorRows = [];

            foreach ($chunk->lines as $line) {
                try {
                    $logData = $this->parser->parse(
                        line: $line->content,
                        lineNumber: $line->lineNumber,
                        byteOffset: $line->byteOffset,
                    );

                    $eventHash = $this->eventHashGenerator->generate($logData);

                    $logRows[] = ApiGatewayLog::makeInsertPayload(
                        logImportId: (int) $import->id,
                        data: $logData,
                        eventHash: $eventHash,
                        processedAt: $processedAt,
                    );
                } catch (Throwable $exception) {
                    $errorRows[] = LogImportError::makeInsertPayload(
                        logImportId: (int) $import->id,
                        lineNumber: $line->lineNumber,
                        byteOffset: $line->byteOffset,
                        rawLine: $line->content,
                        exception: $exception,
                        createdAt: $processedAt,
                    );
                }
            }

            $successfulLines = count($logRows);
            $failedLines = count($errorRows);

            DB::transaction(function () use (
                $import,
                $chunk,
                $logRows,
                $errorRows,
                $successfulLines,
                $failedLines,
                $processedAt,
            ): void {
                if ($logRows !== []) {
                    DB::table('api_gateway_logs')->insert($logRows);
                }

                if ($errorRows !== []) {
                    DB::table('log_import_errors')->insert($errorRows);
                }

                DB::table('log_imports')
                    ->where('id', $import->id)
                    ->update([
                        'current_offset' => $chunk->currentOffset,
                        'last_line_number' => $chunk->lastLineNumber,
                        'total_lines_processed' => DB::raw('total_lines_processed + '.$successfulLines),
                        'total_lines_failed' => DB::raw('total_lines_failed + '.$failedLines),
                        'updated_at' => $processedAt,
                    ]);
            });

            $import->refresh();

            if ($chunk->reachedEndOfFile) {
                $import->markAsFinished();
                $import->refresh();
            }

            return new ImportProgressData(
                currentOffset: (int) $import->current_offset,
                lastLineNumber: (int) $import->last_line_number,
                totalLinesProcessed: (int) $import->total_lines_processed,
                totalLinesFailed: (int) $import->total_lines_failed,
                reachedEndOfFile: $chunk->reachedEndOfFile,
            );
        } catch (Throwable $exception) {
            $import->markAsFailed($exception->getMessage());

            throw $exception;
        }
    }

    private function ensureImportCanBeProcessed(LogImport $import, int $chunkSize): void
    {
        if (! $import->exists) {
            throw new InvalidArgumentException('The log import must be persisted before processing.');
        }

        if ($chunkSize <= 0) {
            throw new InvalidArgumentException('The chunk size must be greater than zero.');
        }

        if ($import->status instanceof LogImportStatus && $import->status->isFinal()) {
            throw new InvalidArgumentException(
                "The log import [{$import->id}] cannot be processed because its status is final."
            );
        }
    }
}
