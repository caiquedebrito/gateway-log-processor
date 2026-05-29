<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

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

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'gateway-log-report-command-'
            .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_queues_report_generation_from_artisan_command(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => ReportType::RequestsByConsumer->value,
        ])
            ->expectsOutputToContain('queued successfully')
            ->expectsOutputToContain('Queue: reports')
            ->assertSuccessful();

        $this->assertSame(1, ReportExport::query()->count());

        $export = ReportExport::query()->firstOrFail();

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

    public function test_it_does_not_generate_csv_synchronously(): void
    {
        Bus::fake();

        $this->artisan('gateway-log:report', [
            'type' => ReportType::AverageLatencyByService->value,
        ])->assertSuccessful();

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame(ReportExportStatus::Queued, $export->status);
        $this->assertNull($export->output_path);
        $this->assertNull($export->started_at);
        $this->assertNull($export->finished_at);
        $this->assertNull($export->failed_at);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            if (is_file($directory)) {
                unlink($directory);
            }

            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
