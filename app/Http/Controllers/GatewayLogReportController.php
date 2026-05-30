<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\GatewayLog\Services\QueueGatewayLogReportExportService;
use App\Domain\GatewayLog\DTO\ReportFiltersData;
use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Http\Requests\StoreGatewayLogReportRequest;
use App\Http\Resources\ReportExportResource;
use App\Models\ReportExport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class GatewayLogReportController extends Controller
{
    public function store(
        StoreGatewayLogReportRequest $request,
        QueueGatewayLogReportExportService $service,
    ): JsonResponse {
        $validated = $request->validated();

        $filters = ReportFiltersData::fromArray([
            'date_field' => $validated['date_field'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ]);

        $export = $service->queue(
            type: ReportType::from($validated['type']),
            filters: $filters,
        );

        return (new ReportExportResource($export))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(ReportExport $reportExport): ReportExportResource
    {
        return new ReportExportResource($reportExport);
    }

    public function download(ReportExport $reportExport): BinaryFileResponse|JsonResponse
    {
        if ($reportExport->status !== ReportExportStatus::Finished) {
            return response()->json([
                'message' => 'Report is not available for download.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (blank($reportExport->output_path)) {
            return response()->json([
                'message' => 'Report output path is missing.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $outputPath = (string) $reportExport->output_path;

        if (! is_file($outputPath) || ! is_readable($outputPath)) {
            return response()->json([
                'message' => 'Report CSV file was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->download(
            file: $outputPath,
            name: basename($outputPath),
            headers: [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ],
        );
    }
}
