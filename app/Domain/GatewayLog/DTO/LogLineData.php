<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\DTO;

use InvalidArgumentException;

final readonly class LogLineData
{
    public function __construct(
        public int $lineNumber,
        public int $byteOffset,
        public string $content,
    ) {
        $this->ensurePositive('lineNumber', $this->lineNumber);
        $this->ensureNonNegative('byteOffset', $this->byteOffset);
    }

    private function ensurePositive(string $field, int $value): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(
                "Log line field [{$field}] must be greater than zero."
            );
        }
    }

    private function ensureNonNegative(string $field, int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException(
                "Log line field [{$field}] cannot be negative."
            );
        }
    }
}
