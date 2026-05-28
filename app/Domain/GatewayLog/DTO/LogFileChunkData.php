<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\DTO;

use InvalidArgumentException;

final readonly class LogFileChunkData
{
    public function __construct(
        public array $lines,
        public int $currentOffset,
        public int $lastLineNumber,
        public int $totalLinesRead,
        public bool $reachedEndOfFile,
    ) {
        $this->ensureOnlyLogLineData($this->lines);
        $this->ensureNonNegative('currentOffset', $this->currentOffset);
        $this->ensureNonNegative('lastLineNumber', $this->lastLineNumber);
        $this->ensureNonNegative('totalLinesRead', $this->totalLinesRead);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    private function ensureOnlyLogLineData(array $lines): void
    {
        foreach ($lines as $line) {
            if (! $line instanceof LogLineData) {
                throw new InvalidArgumentException(
                    'Log file chunk lines must contain only LogLineData instances.'
                );
            }
        }
    }

    private function ensureNonNegative(string $field, int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException(
                "Log file chunk field [{$field}] cannot be negative."
            );
        }
    }
}
