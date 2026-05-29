<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Application\GatewayLog\Reports\GatewayLogReportFactory;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ReportExport;
use Throwable;

final readonly class ExportGatewayLogReportService
{
    public function __construct(
        private GatewayLogReportFactory $factory,
        private CsvReportWriter $writer,
    ) {}

    public function export(
        ReportType $type,
        ?string $outputDirectory = null,
    ): ReportExport {
        $export = ReportExport::query()->create([
            'type' => $type,
            'status' => ReportExportStatus::Queued,
        ]);

        try {
            $export->markAsProcessing();

            $generator = $this->factory->make($type);

            $outputPath = $this->buildOutputPath(
                export: $export,
                type: $type,
                outputDirectory: $outputDirectory,
            );

            $this->writer->write(
                outputPath: $outputPath,
                header: $generator->header(),
                rows: $generator->rows(),
            );

            $export->markAsFinished($outputPath);

            return $export->refresh();
        } catch (Throwable $exception) {
            $export->markAsFailed($exception->getMessage());

            throw $exception;
        }
    }

    private function buildOutputPath(
        ReportExport $export,
        ReportType $type,
        ?string $outputDirectory,
    ): string {
        $directory = $outputDirectory ?: storage_path('app/reports');

        return $directory
          .DIRECTORY_SEPARATOR
          .$export->id
          .'_'
          .$type->defaultFileName();
    }
}
