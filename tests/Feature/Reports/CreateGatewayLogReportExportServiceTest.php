<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\GatewayLog\Services\CreateGatewayLogReportExportService;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportDateField;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateGatewayLogReportExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_queued_report_export_without_filters(): void
    {
        $service = new CreateGatewayLogReportExportService;

        $export = $service->create(ReportType::RequestsByConsumer);

        $this->assertTrue($export->exists);
        $this->assertSame(1, ReportExport::query()->count());

        $this->assertSame(ReportType::RequestsByConsumer, $export->type);
        $this->assertSame(ReportExportStatus::Queued, $export->status);
        $this->assertNull($export->filters);
        $this->assertNull($export->output_path);
        $this->assertNull($export->started_at);
        $this->assertNull($export->finished_at);
        $this->assertNull($export->failed_at);
        $this->assertNull($export->error_message);
    }

    public function test_it_creates_a_queued_report_export_with_started_at_filters(): void
    {
        $service = new CreateGatewayLogReportExportService;

        $filters = new ReportFiltersData(
            dateField: ReportDateField::StartedAt,
            dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
            dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
        );

        $export = $service->create(
            type: ReportType::RequestsByService,
            filters: $filters,
        );

        $export->refresh();

        $this->assertSame(ReportType::RequestsByService, $export->type);
        $this->assertSame(ReportExportStatus::Queued, $export->status);

        $this->assertSame([
            'date_field' => 'started_at',
            'date_from' => '2026-05-01T00:00:00.000000Z',
            'date_to' => '2026-05-31T23:59:59.000000Z',
        ], $export->filters);
    }

    public function test_it_creates_a_queued_report_export_with_processed_at_filters(): void
    {
        $service = new CreateGatewayLogReportExportService;

        $filters = new ReportFiltersData(
            dateField: ReportDateField::ProcessedAt,
            dateFrom: CarbonImmutable::parse('2026-06-01T00:00:00Z'),
            dateTo: CarbonImmutable::parse('2026-06-30T23:59:59Z'),
        );

        $export = $service->create(
            type: ReportType::AverageLatencyByService,
            filters: $filters,
        );

        $export->refresh();

        $this->assertSame([
            'date_field' => 'processed_at',
            'date_from' => '2026-06-01T00:00:00.000000Z',
            'date_to' => '2026-06-30T23:59:59.000000Z',
        ], $export->filters);
    }
}
