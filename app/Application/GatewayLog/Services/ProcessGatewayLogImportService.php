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

            /**
             * Chave: event_hash
             * Valor: payload pronto para insert.
             *
             * Usa o hash como chave para remover duplicados dentro do próprio chunk
             * sem precisar fazer loops caros depois.
             *
             * @var array<string, array<string, mixed>> $logRowsByEventHash
             */
            $logRowsByEventHash = [];

            $errorRows = [];
            $validLinesCount = 0;

            foreach ($chunk->lines as $line) {
                try {
                    $logData = $this->parser->parse(
                        line: $line->content,
                        lineNumber: $line->lineNumber,
                        byteOffset: $line->byteOffset,
                    );

                    $validLinesCount++;

                    $eventHash = $this->eventHashGenerator->generate($logData);

                    if (isset($logRowsByEventHash[$eventHash])) {
                        continue;
                    }

                    $logRowsByEventHash[$eventHash] = ApiGatewayLog::makeInsertPayload(
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

            $newLogRows = $this->filterNewLogRows($logRowsByEventHash);
            $failedLines = count($errorRows);

            DB::transaction(function () use (
                $import,
                $chunk,
                $newLogRows,
                $errorRows,
                $validLinesCount,
                $failedLines,
                $processedAt,
            ): void {
                if ($newLogRows !== []) {
                    DB::table('api_gateway_logs')->insertOrIgnore($newLogRows);
                }

                if ($errorRows !== []) {
                    DB::table('log_import_errors')->insert($errorRows);
                }

                DB::table('log_imports')
                    ->where('id', $import->id)
                    ->update([
                        'current_offset' => $chunk->currentOffset,
                        'last_line_number' => $chunk->lastLineNumber,
                        'total_lines_processed' => DB::raw('total_lines_processed + '.$validLinesCount),
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

    public function process(LogImport $import, int $chunkSize = 1000): ImportProgressData
    {
        return $this->processChunk($import, $chunkSize);
    }

    private function filterNewLogRows(array $logRowsByEventHash): array
    {
        if ($logRowsByEventHash === []) {
            return [];
        }

        $eventHashes = array_keys($logRowsByEventHash);
        $existingHashes = $this->findExistingEventHashes($eventHashes);

        if ($existingHashes === []) {
            return array_values($logRowsByEventHash);
        }

        foreach ($existingHashes as $existingHash) {
            unset($logRowsByEventHash[$existingHash]);
        }

        return array_values($logRowsByEventHash);
    }

    private function findExistingEventHashes(array $eventHashes): array
    {
        $existingHashes = [];

        foreach (array_chunk($eventHashes, 1000) as $hashChunk) {
            $rows = DB::table('api_gateway_logs')
                ->whereIn('event_hash', $hashChunk)
                ->pluck('event_hash');

            foreach ($rows as $hash) {
                $existingHashes[(string) $hash] = (string) $hash;
            }
        }

        return $existingHashes;
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
