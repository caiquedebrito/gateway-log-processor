<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\Enums;

enum LogImportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Finished = 'finished';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Finished,
            self::Failed,
            self::Canceled => true,

            self::Queued,
            self::Processing => false,
        };
    }

    public function canBeRetried(): bool
    {
        return $this === self::Failed;
    }
}
