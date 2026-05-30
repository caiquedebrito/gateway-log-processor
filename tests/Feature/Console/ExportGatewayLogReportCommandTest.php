<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\GatewayLog\Enums\ReportDateField;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Jobs\ExportGatewayLogReportJob;
use App\Models\ReportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ExportGatewayLogReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_report_generation_without_filters(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => ReportType::RequestsByConsumer->value,
        ])
            ->expectsOutputToContain('queued successfully')
            ->expectsOutputToContain('Queue: reports')
            ->expectsOutputToContain('Date filters: none')
            ->assertSuccessful();

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame(ReportType::RequestsByConsumer, $export->type);
        $this->assertSame(ReportExportStatus::Queued, $export->status);
        $this->assertSame(1, ReportExport::query()->count());
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

    public function test_it_queues_report_generation_with_started_at_filters(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => ReportType::RequestsByService->value,
            '--date-field' => ReportDateField::StartedAt->value,
            '--from' => '2026-05-01T00:00:00Z',
            '--to' => '2026-05-31T23:59:59Z',
        ])
            ->expectsOutputToContain('queued successfully')
            ->expectsOutputToContain('Date field: started_at')
            ->expectsOutputToContain('From: 2026-05-01T00:00:00.000000Z')
            ->expectsOutputToContain('To: 2026-05-31T23:59:59.000000Z')
            ->assertSuccessful();

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame(ReportType::RequestsByService, $export->type);
        $this->assertSame(ReportExportStatus::Queued, $export->status);

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

    public function test_it_queues_report_generation_with_processed_at_filters(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => ReportType::AverageLatencyByService->value,
            '--date-field' => ReportDateField::ProcessedAt->value,
            '--from' => '2026-06-01T00:00:00Z',
            '--to' => '2026-06-30T23:59:59Z',
        ])
            ->expectsOutputToContain('queued successfully')
            ->expectsOutputToContain('Date field: processed_at')
            ->assertSuccessful();

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame(ReportType::AverageLatencyByService, $export->type);
        $this->assertSame(ReportExportStatus::Queued, $export->status);

        $this->assertSame([
            'date_field' => 'processed_at',
            'date_from' => '2026-06-01T00:00:00.000000Z',
            'date_to' => '2026-06-30T23:59:59.000000Z',
        ], $export->filters);

        Bus::assertDispatched(ExportGatewayLogReportJob::class);
    }

    public function test_it_defaults_to_started_at_when_period_is_sent_without_date_field(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => ReportType::RequestsByConsumer->value,
            '--from' => '2026-05-01T00:00:00Z',
            '--to' => '2026-05-31T23:59:59Z',
        ])
            ->expectsOutputToContain('Date field: started_at')
            ->assertSuccessful();

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame([
            'date_field' => 'started_at',
            'date_from' => '2026-05-01T00:00:00.000000Z',
            'date_to' => '2026-05-31T23:59:59.000000Z',
        ], $export->filters);

        Bus::assertDispatched(ExportGatewayLogReportJob::class);
    }

    public function test_it_queues_report_generation_with_custom_output_directory(): void
    {
        Bus::fake();

        $outputDirectory = 'storage/app/custom-reports';

        $this->artisan('gateway-log:report', [
            'type' => ReportType::RequestsByService->value,
            '--output' => $outputDirectory,
        ])
            ->expectsOutputToContain('queued successfully')
            ->expectsOutputToContain('Output directory:')
            ->assertSuccessful();

        $export = ReportExport::query()->firstOrFail();

        Bus::assertDispatched(
            ExportGatewayLogReportJob::class,
            function (ExportGatewayLogReportJob $job) use ($export, $outputDirectory): bool {
                return $job->reportExportId === $export->id
                    && $job->outputDirectory === base_path($outputDirectory)
                    && $job->queue === 'reports';
            }
        );
    }

    public function test_it_fails_when_report_type_is_invalid(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => 'invalid_report_type',
        ])
            ->expectsOutputToContain('Invalid report type.')
            ->expectsOutputToContain(ReportType::RequestsByConsumer->value)
            ->expectsOutputToContain(ReportType::RequestsByService->value)
            ->expectsOutputToContain(ReportType::AverageLatencyByService->value)
            ->assertFailed();

        $this->assertSame(0, ReportExport::query()->count());

        Bus::assertNotDispatched(ExportGatewayLogReportJob::class);
    }

    public function test_it_fails_when_date_field_is_invalid(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => ReportType::RequestsByConsumer->value,
            '--date-field' => 'created_at',
            '--from' => '2026-05-01T00:00:00Z',
            '--to' => '2026-05-31T23:59:59Z',
        ])
            ->expectsOutputToContain('Invalid report filters.')
            ->expectsOutputToContain('date_field filter must be started_at or processed_at')
            ->assertFailed();

        $this->assertSame(0, ReportExport::query()->count());

        Bus::assertNotDispatched(ExportGatewayLogReportJob::class);
    }

    public function test_it_fails_when_date_to_is_before_date_from(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => ReportType::RequestsByConsumer->value,
            '--date-field' => ReportDateField::StartedAt->value,
            '--from' => '2026-05-31T23:59:59Z',
            '--to' => '2026-05-01T00:00:00Z',
        ])
            ->expectsOutputToContain('Invalid report filters.')
            ->expectsOutputToContain('date_to filter must be greater than or equal to date_from')
            ->assertFailed();

        $this->assertSame(0, ReportExport::query()->count());

        Bus::assertNotDispatched(ExportGatewayLogReportJob::class);
    }

    public function test_it_does_not_generate_csv_synchronously(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => ReportType::RequestsByConsumer->value,
            '--date-field' => ReportDateField::StartedAt->value,
            '--from' => '2026-05-01T00:00:00Z',
            '--to' => '2026-05-31T23:59:59Z',
        ])->assertSuccessful();

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame(ReportExportStatus::Queued, $export->status);
        $this->assertNotNull($export->filters);
        $this->assertNull($export->output_path);
        $this->assertNull($export->started_at);
        $this->assertNull($export->finished_at);
        $this->assertNull($export->failed_at);

        Bus::assertDispatched(ExportGatewayLogReportJob::class);
    }
}
