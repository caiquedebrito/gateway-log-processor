<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\Exceptions;

use RuntimeException;
use Throwable;

final class InvalidLogLineException extends RuntimeException
{
    public static function invalidJson(int $lineNumber, Throwable $previous): self
    {
        return new self(
            message: "Invalid JSON at line {$lineNumber}.",
            previous: $previous,
        );
    }

    public static function invalidStructure(int $lineNumber): self
    {
        return new self("Invalid log structure at line {$lineNumber}.");
    }

    public static function missingStartedAt(int $lineNumber): self
    {
        return new self("Missing started_at at line {$lineNumber}.");
    }

    public static function invalidStartedAt(int $lineNumber): self
    {
        return new self("Invalid started_at at line {$lineNumber}.");
    }
}
