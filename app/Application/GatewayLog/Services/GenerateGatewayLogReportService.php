<?php

declare(strict_types=1);

namespace App\Application\GatewayLog\Services;

use App\Application\GatewayLog\Reports\GatewayLogReportFactory;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ReportExport;
use InvalidArgumentException;
use Throwable;

final readonly class GenerateGatewayLogReportService
{
    public function __construct(
        private GatewayLogReportFactory $factory,
        private CsvReportWriter $writer,
    ) {}

    public function generate(
        ReportExport $export,
        ?string $outputDirectory = null,
    ): ReportExport {
        $export->refresh();

        $this->ensureReportCanBeGenerated($export);

        $export->refresh();

        try {
            $export->markAsProcessing();

            $type = $this->resolveReportType($export);

            $generator = $this->factory->make($type);

            $filters = ReportFiltersData::fromArray($export->filters);

            $outputPath = $this->buildOutputPath(
                export: $export,
                type: $type,
                outputDirectory: $outputDirectory,
            );

            $this->writer->write(
                outputPath: $outputPath,
                header: $generator->header(),
                rows: $generator->rows($filters),
            );

            $export->markAsFinished($outputPath);

            return $export->refresh();
        } catch (Throwable $exception) {
            $export->markAsFailed($exception->getMessage());

            throw $exception;
        }
    }

    private function ensureReportCanBeGenerated(ReportExport $export): void
    {
        if (! $export->exists) {
            throw new InvalidArgumentException(
                'The report export must be persisted before generation.'
            );
        }

        if ($export->status instanceof ReportExportStatus && $export->status->isFinal()) {
            throw new InvalidArgumentException(
                "The report export [{$export->id}] cannot be generated because its status is final."
            );
        }
    }

    private function resolveReportType(ReportExport $export): ReportType
    {
        if ($export->type instanceof ReportType) {
            return $export->type;
        }

        return ReportType::from((string) $export->type);
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
