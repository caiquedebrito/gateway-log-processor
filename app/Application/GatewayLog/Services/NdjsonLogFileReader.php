<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Domain\GatewayLog\DTO\LogFileChunkData;
use App\Domain\GatewayLog\DTO\LogLineData;
use InvalidArgumentException;
use RuntimeException;

final class NdjsonLogFileReader
{
    public function read(
        string $filePath,
        int $fromOffset = 0,
        int $fromLineNumber = 0,
        int $limit = 1000,
    ): LogFileChunkData {
        $this->validateInput($filePath, $fromOffset, $fromLineNumber, $limit);

        $fileSize = $this->fileSize($filePath);

        if ($fromOffset > $fileSize) {
            throw new InvalidArgumentException(
                "The offset [{$fromOffset}] is greater than the file size [{$fileSize}]."
            );
        }

        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open log file [{$filePath}].");
        }

        try {
            if (fseek($handle, $fromOffset) !== 0) {
                throw new RuntimeException("Could not seek log file to offset [{$fromOffset}].");
            }

            $lines = [];
            $currentOffset = $fromOffset;
            $lastLineNumber = $fromLineNumber;
            $totalLinesRead = 0;

            while ($totalLinesRead < $limit) {
                $byteOffset = $this->tell($handle);

                $line = fgets($handle);

                if ($line === false) {
                    break;
                }

                $lastLineNumber++;
                $totalLinesRead++;

                $lines[] = new LogLineData(
                    lineNumber: $lastLineNumber,
                    byteOffset: $byteOffset,
                    content: $line,
                );

                $currentOffset = $this->tell($handle);
            }

            return new LogFileChunkData(
                lines: $lines,
                currentOffset: $currentOffset,
                lastLineNumber: $lastLineNumber,
                totalLinesRead: $totalLinesRead,
                reachedEndOfFile: $currentOffset >= $fileSize,
            );
        } finally {
            fclose($handle);
        }
    }

    private function validateInput(
        string $filePath,
        int $fromOffset,
        int $fromLineNumber,
        int $limit,
    ): void {
        if (! is_file($filePath)) {
            throw new InvalidArgumentException("Log file [{$filePath}] does not exist.");
        }

        if (! is_readable($filePath)) {
            throw new InvalidArgumentException("Log file [{$filePath}] is not readable.");
        }

        if ($fromOffset < 0) {
            throw new InvalidArgumentException('The initial offset cannot be negative.');
        }

        if ($fromLineNumber < 0) {
            throw new InvalidArgumentException('The initial line number cannot be negative.');
        }

        if ($limit <= 0) {
            throw new InvalidArgumentException('The read limit must be greater than zero.');
        }
    }

    private function fileSize(string $filePath): int
    {
        $fileSize = filesize($filePath);

        if ($fileSize === false) {
            throw new RuntimeException("Could not determine size of log file [{$filePath}].");
        }

        return $fileSize;
    }

    private function tell(mixed $handle): int
    {
        $position = ftell($handle);

        if ($position === false) {
            throw new RuntimeException('Could not determine current file offset.');
        }

        return $position;
    }
}
