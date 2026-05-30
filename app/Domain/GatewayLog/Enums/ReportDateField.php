<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog\Enums;

enum ReportDateField: string
{
    case StartedAt = 'started_at';
    case ProcessedAt = 'processed_at';

    public function label(): string
    {
        return match ($this) {
            self::StartedAt => 'Request original time',
            self::ProcessedAt => 'Data ingestion time',
        };
    }
}
