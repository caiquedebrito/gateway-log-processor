<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportExport
 */
final class ReportExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type instanceof ReportType
                ? $this->type->value
                : $this->type,
            'status' => $this->status instanceof ReportExportStatus
                ? $this->status->value
                : $this->status,
            'output_path' => $this->output_path,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
