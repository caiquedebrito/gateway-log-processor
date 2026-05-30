<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportDateField;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReportExportFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_report_filters_as_array(): void
    {
        $filters = new ReportFiltersData(
            dateField: ReportDateField::StartedAt,
            dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
            dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
        );

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
            'filters' => $filters->toDatabaseArray(),
        ]);

        $export->refresh();

        $this->assertIsArray($export->filters);

        $this->assertSame('started_at', $export->filters['date_field']);
        $this->assertSame('2026-05-01T00:00:00.000000Z', $export->filters['date_from']);
        $this->assertSame('2026-05-31T23:59:59.000000Z', $export->filters['date_to']);

        $restoredFilters = ReportFiltersData::fromArray($export->filters);

        $this->assertSame(ReportDateField::StartedAt, $restoredFilters->dateField);
        $this->assertSame('2026-05-01 00:00:00', $restoredFilters->dateFrom?->toDateTimeString());
        $this->assertSame('2026-05-31 23:59:59', $restoredFilters->dateTo?->toDateTimeString());
    }

    public function test_it_allows_report_export_without_filters(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByService,
            'status' => ReportExportStatus::Queued,
            'filters' => null,
        ]);

        $export->refresh();

        $this->assertNull($export->filters);

        $filters = ReportFiltersData::fromArray($export->filters);

        $this->assertFalse($filters->hasDateFilters());
    }
}
