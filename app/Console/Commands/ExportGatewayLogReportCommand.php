<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\GatewayLog\Services\QueueGatewayLogReportExportService;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportType;
use Illuminate\Console\Command;
use Throwable;

final class ExportGatewayLogReportCommand extends Command
{
    protected $signature = 'gateway-log:report
        {type : Report type: requests_by_consumer, requests_by_service, average_latency_by_service}
        {--output= : Directory where the generated CSV file will be stored}
        {--date-field= : Date field used for filtering: started_at or processed_at}
        {--from= : Start datetime filter, example: 2026-05-01T00:00:00Z}
        {--to= : End datetime filter, example: 2026-05-31T23:59:59Z}';

    protected $description = 'Queue API Gateway log report generation in CSV format.';

    public function handle(QueueGatewayLogReportExportService $service): int
    {
        $type = ReportType::tryFrom((string) $this->argument('type'));

        if (! $type instanceof ReportType) {
            $this->error('Invalid report type.');
            $this->line('Available report types:');

            foreach (ReportType::cases() as $availableType) {
                $this->line("- {$availableType->value}");
            }

            return self::FAILURE;
        }

        try {
            $filters = $this->buildFilters();
        } catch (Throwable $exception) {
            $this->error('Invalid report filters.');
            $this->line("Reason: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $outputDirectory = $this->resolveOutputDirectory(
            $this->option('output') !== null
                ? (string) $this->option('output')
                : null,
        );

        $export = $service->queue(
            type: $type,
            filters: $filters,
            outputDirectory: $outputDirectory,
        );

        $this->info("Gateway log report [{$export->id}] queued successfully.");
        $this->line("Type: {$export->type->value}");
        $this->line("Status: {$export->status->value}");
        $this->line('Queue: reports');

        $this->writeFilterOutput($filters);

        if ($outputDirectory !== null) {
            $this->line("Output directory: {$outputDirectory}");
        }

        return self::SUCCESS;
    }

    private function buildFilters(): ReportFiltersData
    {
        $dateField = $this->option('date-field');
        $dateFrom = $this->option('from');
        $dateTo = $this->option('to');

        return ReportFiltersData::fromArray([
            'date_field' => $dateField !== null ? (string) $dateField : null,
            'date_from' => $dateFrom !== null ? (string) $dateFrom : null,
            'date_to' => $dateTo !== null ? (string) $dateTo : null,
        ]);
    }

    private function writeFilterOutput(ReportFiltersData $filters): void
    {
        if (! $filters->hasDateFilters()) {
            $this->line('Date filters: none');

            return;
        }

        $this->line("Date field: {$filters->resolvedDateField()->value}");

        if ($filters->dateFrom !== null) {
            $this->line("From: {$filters->dateFrom->toISOString()}");
        }

        if ($filters->dateTo !== null) {
            $this->line("To: {$filters->dateTo->toISOString()}");
        }
    }

    private function resolveOutputDirectory(?string $outputDirectory): ?string
    {
        if ($outputDirectory === null || trim($outputDirectory) === '') {
            return null;
        }

        if ($this->isAbsolutePath($outputDirectory)) {
            return $outputDirectory;
        }

        return base_path($outputDirectory);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
