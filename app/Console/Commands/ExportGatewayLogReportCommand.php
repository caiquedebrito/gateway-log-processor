<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\GatewayLog\Services\ExportGatewayLogReportService;
use App\Domain\GatewayLog\Enums\ReportType;
use Illuminate\Console\Command;
use Throwable;

final class ExportGatewayLogReportCommand extends Command
{
    protected $signature = 'gateway-log:report
        {type : Report type: requests_by_consumer, requests_by_service, average_latency_by_service}
        {--output= : Directory where the generated CSV file will be stored}';

    protected $description = 'Generate API Gateway log reports in CSV format.';

    public function handle(ExportGatewayLogReportService $service): int
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
                : null
        );

        try {
            $export = $service->export(
                type: $type,
                outputDirectory: $outputDirectory,
            );

            $this->info("Gateway log report [{$export->id}] generated successfully.");
            $this->line("Type: {$export->type->value}");
            $this->line("Output: {$export->output_path}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Could not generate gateway log report.');
            $this->line($exception->getMessage());

            return self::FAILURE;
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
