<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\GatewayLog\Services\CreateGatewayLogReportExportService;
use App\Application\GatewayLog\Services\QueueGatewayLogReportExportService;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Jobs\ExportGatewayLogReportJob;
use App\Models\ReportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class QueueGatewayLogReportExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_report_export_and_dispatches_job_on_reports_queue(): void
    {
        Bus::fake();

        $service = new QueueGatewayLogReportExportService(
            createReportExportService: new CreateGatewayLogReportExportService,
        );

        $export = $service->queue(ReportType::RequestsByConsumer);

        $this->assertTrue($export->exists);
        $this->assertSame(1, ReportExport::query()->count());

        $this->assertSame(ReportType::RequestsByConsumer, $export->type);
        $this->assertSame(ReportExportStatus::Queued, $export->status);
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

    public function test_it_dispatches_job_with_custom_output_directory(): void
    {
        Bus::fake();

        $service = new QueueGatewayLogReportExportService(
            createReportExportService: new CreateGatewayLogReportExportService,
        );

        $outputDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gateway-report-output';

        $export = $service->queue(
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
}
