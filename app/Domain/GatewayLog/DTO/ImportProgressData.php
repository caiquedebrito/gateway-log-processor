<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\DTO;

use InvalidArgumentException;

final readonly class ImportProgressData
{
    public function __construct(
        public int $currentOffset,
        public int $lastLineNumber,
        public int $totalLinesProcessed,
        public int $totalLinesFailed,
        public bool $reachedEndOfFile,
    ) {
        $this->ensureNonNegative('currentOffset', $this->currentOffset);
        $this->ensureNonNegative('lastLineNumber', $this->lastLineNumber);
        $this->ensureNonNegative('totalLinesProcessed', $this->totalLinesProcessed);
        $this->ensureNonNegative('totalLinesFailed', $this->totalLinesFailed);
    }

    private function ensureNonNegative(string $field, int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException(
                "Progress field [{$field}] cannot be negative."
            );
        }
    }
}
