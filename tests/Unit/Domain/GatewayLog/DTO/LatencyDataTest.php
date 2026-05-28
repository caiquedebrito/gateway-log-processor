<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\Enums;

enum ReportExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Finished = 'finished';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Finished,
            self::Failed => true,

            self::Queued,
            self::Processing => false,
        };
    }
}
