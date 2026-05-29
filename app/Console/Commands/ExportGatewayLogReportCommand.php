<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\GatewayLog\Services\QueueGatewayLogReportExportService;
use App\Domain\GatewayLog\Enums\ReportType;
use Illuminate\Console\Command;

final class ExportGatewayLogReportCommand extends Command
{
    protected $signature = 'gateway-log:report
        {type : Report type: requests_by_consumer, requests_by_service, average_latency_by_service}
        {--output= : Directory where the generated CSV file will be stored}';

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

        $outputDirectory = $this->resolveOutputDirectory(
            $this->option('output') !== null
                ? (string) $this->option('output')
                : null,
        );

        $export = $service->queue(
            type: $type,
            outputDirectory: $outputDirectory,
        );

        $this->info("Gateway log report [{$export->id}] queued successfully.");
        $this->line("Type: {$export->type->value}");
        $this->line("Status: {$export->status->value}");
        $this->line('Queue: reports');

        if ($outputDirectory !== null) {
            $this->line("Output directory: {$outputDirectory}");
        }

        return self::SUCCESS;
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
