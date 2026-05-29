<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\GatewayLog\Services\CreateGatewayLogReportExportService;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ReportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateGatewayLogReportExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_queued_report_export_without_generating_csv(): void
    {
        $service = new CreateGatewayLogReportExportService;

        $export = $service->create(ReportType::RequestsByConsumer);

        $this->assertTrue($export->exists);
        $this->assertSame(1, ReportExport::query()->count());

        $this->assertSame(ReportType::RequestsByConsumer, $export->type);
        $this->assertSame(ReportExportStatus::Queued, $export->status);

        $this->assertNull($export->output_path);
        $this->assertNull($export->started_at);
        $this->assertNull($export->finished_at);
        $this->assertNull($export->failed_at);
        $this->assertNull($export->error_message);
    }
}
