<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Jobs\ExportGatewayLogReportJob;
use App\Models\ReportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class GatewayLogReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'gateway-log-report-api-'
            .bin2hex(random_bytes(8));

        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_queues_requests_by_consumer_report_generation(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/gateway-log/reports', [
            'type' => ReportType::RequestsByConsumer->value,
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.type', ReportType::RequestsByConsumer->value)
            ->assertJsonPath('data.status', ReportExportStatus::Queued->value)
            ->assertJsonPath('data.output_path', null)
            ->assertJsonPath('data.started_at', null)
            ->assertJsonPath('data.finished_at', null)
            ->assertJsonPath('data.failed_at', null)
            ->assertJsonPath('data.error_message', null);

        $this->assertSame(1, ReportExport::query()->count());

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame(ReportType::RequestsByConsumer, $export->type);
        $this->assertSame(ReportExportStatus::Queued, $export->status);
        $this->assertNull($export->output_path);

        Bus::assertDispatched(
            ExportGatewayLogReportJob::class,
            function (ExportGatewayLogReportJob $job) use ($export): bool {
                return $job->reportExportId === $export->id
                    && $job->queue === 'reports';
            }
        );
    }

    public function test_it_queues_requests_by_service_report_generation(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/gateway-log/reports', [
            'type' => ReportType::RequestsByService->value,
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.type', ReportType::RequestsByService->value)
            ->assertJsonPath('data.status', ReportExportStatus::Queued->value);

        $export = ReportExport::query()->firstOrFail();

        Bus::assertDispatched(
            ExportGatewayLogReportJob::class,
            function (ExportGatewayLogReportJob $job) use ($export): bool {
                return $job->reportExportId === $export->id
                    && $job->queue === 'reports';
            }
        );
    }

    public function test_it_queues_average_latency_by_service_report_generation(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/gateway-log/reports', [
            'type' => ReportType::AverageLatencyByService->value,
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.type', ReportType::AverageLatencyByService->value)
            ->assertJsonPath('data.status', ReportExportStatus::Queued->value);

        $export = ReportExport::query()->firstOrFail();

        Bus::assertDispatched(
            ExportGatewayLogReportJob::class,
            function (ExportGatewayLogReportJob $job) use ($export): bool {
                return $job->reportExportId === $export->id
                    && $job->queue === 'reports';
            }
        );
    }

    public function test_it_returns_validation_error_when_type_is_missing(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/gateway-log/reports', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);

        $this->assertSame(0, ReportExport::query()->count());

        Bus::assertNotDispatched(ExportGatewayLogReportJob::class);
    }

    public function test_it_returns_validation_error_when_type_is_invalid(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/gateway-log/reports', [
            'type' => 'invalid_report_type',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);

        $this->assertSame(0, ReportExport::query()->count());

        Bus::assertNotDispatched(ExportGatewayLogReportJob::class);
    }

    public function test_it_does_not_generate_csv_synchronously(): void
    {
        Bus::fake();

        $this->postJson('/api/gateway-log/reports', [
            'type' => ReportType::RequestsByConsumer->value,
        ])->assertAccepted();

        $export = ReportExport::query()->firstOrFail();

        $this->assertSame(ReportExportStatus::Queued, $export->status);
        $this->assertNull($export->output_path);
        $this->assertNull($export->started_at);
        $this->assertNull($export->finished_at);
        $this->assertNull($export->failed_at);
    }

    public function test_it_returns_queued_report_status(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
        ]);

        $response = $this->getJson("/api/gateway-log/reports/{$export->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $export->id)
            ->assertJsonPath('data.type', ReportType::RequestsByConsumer->value)
            ->assertJsonPath('data.status', ReportExportStatus::Queued->value)
            ->assertJsonPath('data.output_path', null)
            ->assertJsonPath('data.started_at', null)
            ->assertJsonPath('data.finished_at', null)
            ->assertJsonPath('data.failed_at', null)
            ->assertJsonPath('data.error_message', null);
    }

    public function test_it_returns_processing_report_status(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByService,
            'status' => ReportExportStatus::Processing,
            'started_at' => now(),
        ]);

        $response = $this->getJson("/api/gateway-log/reports/{$export->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $export->id)
            ->assertJsonPath('data.type', ReportType::RequestsByService->value)
            ->assertJsonPath('data.status', ReportExportStatus::Processing->value)
            ->assertJsonPath('data.output_path', null)
            ->assertJsonPath('data.finished_at', null)
            ->assertJsonPath('data.failed_at', null)
            ->assertJsonPath('data.error_message', null);

        $this->assertNotNull($response->json('data.started_at'));
    }

    public function test_it_returns_finished_report_status(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::AverageLatencyByService,
            'status' => ReportExportStatus::Finished,
            'output_path' => 'storage/app/reports/1_average_latency_by_service.csv',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $response = $this->getJson("/api/gateway-log/reports/{$export->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $export->id)
            ->assertJsonPath('data.type', ReportType::AverageLatencyByService->value)
            ->assertJsonPath('data.status', ReportExportStatus::Finished->value)
            ->assertJsonPath('data.output_path', 'storage/app/reports/1_average_latency_by_service.csv')
            ->assertJsonPath('data.failed_at', null)
            ->assertJsonPath('data.error_message', null);

        $this->assertNotNull($response->json('data.started_at'));
        $this->assertNotNull($response->json('data.finished_at'));
    }

    public function test_it_returns_failed_report_status(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Failed,
            'started_at' => now()->subMinute(),
            'failed_at' => now(),
            'error_message' => 'Could not write CSV file.',
        ]);

        $response = $this->getJson("/api/gateway-log/reports/{$export->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $export->id)
            ->assertJsonPath('data.type', ReportType::RequestsByConsumer->value)
            ->assertJsonPath('data.status', ReportExportStatus::Failed->value)
            ->assertJsonPath('data.output_path', null)
            ->assertJsonPath('data.finished_at', null)
            ->assertJsonPath('data.error_message', 'Could not write CSV file.');

        $this->assertNotNull($response->json('data.started_at'));
        $this->assertNotNull($response->json('data.failed_at'));
    }

    public function test_it_returns_not_found_when_report_does_not_exist(): void
    {
        $response = $this->getJson('/api/gateway-log/reports/999999');

        $response->assertNotFound();
    }

    public function test_it_downloads_finished_csv_report(): void
    {
        $csvPath = $this->createTemporaryCsvFile(
            filename: '1_requests_by_consumer.csv',
            content: "consumer_id,total\nconsumer-a,2\nconsumer-b,1\n",
        );

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Finished,
            'output_path' => $csvPath,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $response = $this->get("/api/gateway-log/reports/{$export->id}/download");

        $response->assertOk();

        $contentDisposition = $response->baseResponse->headers->get('content-disposition');

        $this->assertIsString($contentDisposition);
        $this->assertStringContainsString('attachment', $contentDisposition);
        $this->assertStringContainsString('1_requests_by_consumer.csv', $contentDisposition);
    }

    public function test_it_does_not_download_queued_report(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Queued,
        ]);

        $response = $this->getJson("/api/gateway-log/reports/{$export->id}/download");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Report is not available for download.');
    }

    public function test_it_does_not_download_processing_report(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByService,
            'status' => ReportExportStatus::Processing,
            'started_at' => now(),
        ]);

        $response = $this->getJson("/api/gateway-log/reports/{$export->id}/download");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Report is not available for download.');
    }

    public function test_it_does_not_download_failed_report(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::AverageLatencyByService,
            'status' => ReportExportStatus::Failed,
            'started_at' => now()->subMinute(),
            'failed_at' => now(),
            'error_message' => 'Could not write CSV file.',
        ]);

        $response = $this->getJson("/api/gateway-log/reports/{$export->id}/download");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Report is not available for download.');
    }

    public function test_it_returns_error_when_finished_report_has_no_output_path(): void
    {
        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Finished,
            'output_path' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $response = $this->getJson("/api/gateway-log/reports/{$export->id}/download");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Report output path is missing.');
    }

    public function test_it_returns_not_found_when_csv_file_does_not_exist(): void
    {
        $missingPath = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .'missing-report.csv';

        $export = ReportExport::query()->create([
            'type' => ReportType::RequestsByConsumer,
            'status' => ReportExportStatus::Finished,
            'output_path' => $missingPath,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $response = $this->getJson("/api/gateway-log/reports/{$export->id}/download");

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Report CSV file was not found.');
    }

    public function test_it_returns_not_found_when_report_download_does_not_exist(): void
    {
        $response = $this->getJson('/api/gateway-log/reports/999999/download');

        $response->assertNotFound();
    }

    private function createTemporaryCsvFile(string $filename, string $content): string
    {
        $path = $this->temporaryDirectory
            .DIRECTORY_SEPARATOR
            .$filename;

        file_put_contents($path, $content);

        return $path;
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
