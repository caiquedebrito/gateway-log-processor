<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\GatewayLog\Services\QueueGatewayLogReportExportService;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Http\Requests\StoreGatewayLogReportRequest;
use App\Http\Resources\ReportExportResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class GatewayLogReportController extends Controller
{
    public function store(
        StoreGatewayLogReportRequest $request,
        QueueGatewayLogReportExportService $service,
    ): JsonResponse {
        $validated = $request->validated();

        $export = $service->queue(
            type: ReportType::from($validated['type']),
        );

        return (new ReportExportResource($export))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
