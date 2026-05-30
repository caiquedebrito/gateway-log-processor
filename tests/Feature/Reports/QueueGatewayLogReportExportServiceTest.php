<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\GatewayLog\Services\CreateGatewayLogReportExportService;
use App\Application\GatewayLog\Services\QueueGatewayLogReportExportService;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportDateField;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Jobs\ExportGatewayLogReportJob;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class QueueGatewayLogReportExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_report_export_and_dispatches_job_on_reports_queue_without_filters(): void
    {
        Bus::fake();

        $service = $this->makeService();

        $export = $service->queue(ReportType::RequestsByConsumer);

        $this->assertTrue($export->exists);
        $this->assertSame(1, ReportExport::query()->count());

        $this->assertSame(ReportType::RequestsByConsumer, $export->type);
        $this->assertSame(ReportExportStatus::Queued, $export->status);
        $this->assertNull($export->filters);
        $this->assertNull($export->output_path);
        $this->assertNull($export->started_at);
        $this->assertNull($export->finished_at);
        $this->assertNull($export->failed_at);

        Bus::assertDispatched(
            ExportGatewayLogReportJob::class,
            function (ExportGatewayLogReportJob $job) use ($export): bool {
                return $job->reportExportId === $export->id
                    && $job->outputDirectory === null
                    && $job->queue === 'reports';
            }
        );
    }

    public function test_it_creates_report_export_with_started_at_filters_and_dispatches_job(): void
    {
        Bus::fake();

        $filters = new ReportFiltersData(
            dateField: ReportDateField::StartedAt,
            dateFrom: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
            dateTo: CarbonImmutable::parse('2026-05-31T23:59:59Z'),
        );

        $export = $this->makeService()->queue(
            type: ReportType::RequestsByConsumer,
            filters: $filters,
        );

        $export->refresh();

        $this->assertSame([
            'date_field' => 'started_at',
            'date_from' => '2026-05-01T00:00:00.000000Z',
            'date_to' => '2026-05-31T23:59:59.000000Z',
        ], $export->filters);

        Bus::assertDispatched(
            ExportGatewayLogReportJob::class,
            function (ExportGatewayLogReportJob $job) use ($export): bool {
                return $job->reportExportId === $export->id
                    && $job->queue === 'reports';
            }
        );
    }

    public function test_it_creates_report_export_with_processed_at_filters_and_dispatches_job(): void
    {
        Bus::fake();

        $filters = new ReportFiltersData(
            dateField: ReportDateField::ProcessedAt,
            dateFrom: CarbonImmutable::parse('2026-06-01T00:00:00Z'),
            dateTo: CarbonImmutable::parse('2026-06-30T23:59:59Z'),
        );

        $export = $this->makeService()->queue(
            type: ReportType::RequestsByService,
            filters: $filters,
        );

        $export->refresh();

        $this->assertSame([
            'date_field' => 'processed_at',
            'date_from' => '2026-06-01T00:00:00.000000Z',
            'date_to' => '2026-06-30T23:59:59.000000Z',
        ], $export->filters);

        Bus::assertDispatched(ExportGatewayLogReportJob::class);
    }

    public function test_it_dispatches_job_with_custom_output_directory(): void
    {
        Bus::fake();

        $outputDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gateway-report-output';

        $export = $this->makeService()->queue(
            type: ReportType::RequestsByService,
            outputDirectory: $outputDirectory,
        );

        Bus::assertDispatched(
            ExportGatewayLogReportJob::class,
            function (ExportGatewayLogReportJob $job) use ($export, $outputDirectory): bool {
                return $job->reportExportId === $export->id
                    && $job->outputDirectory === $outputDirectory
                    && $job->queue === 'reports';
            }
        );
    }

    private function makeService(): QueueGatewayLogReportExportService
    {
        return new QueueGatewayLogReportExportService(
            createReportExportService: new CreateGatewayLogReportExportService,
        );
    }
}
