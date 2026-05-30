<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Reports\Support;

use App\Domain\GatewayLog\DTO\ReportFiltersData;
use Illuminate\Database\Query\Builder;

final class GatewayLogReportQueryFilter
{
    public function apply(Builder $query, ReportFiltersData $filters): Builder
    {
        if (! $filters->hasDateFilters()) {
            return $query;
        }

        $dateColumn = $filters->resolvedDateField()->value;

        if ($filters->dateFrom !== null) {
            $query->where(
                $dateColumn,
                '>=',
                $filters->dateFrom->utc()->toDateTimeString(),
            );
        }

        if ($filters->dateTo !== null) {
            $query->where(
                $dateColumn,
                '<=',
                $filters->dateTo->utc()->toDateTimeString(),
            );
        }

        return $query;
    }
}
