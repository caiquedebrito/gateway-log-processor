<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\GatewayLog\Enums\ReportExportStatus;
use App\Domain\GatewayLog\Enums\ReportType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ReportExport extends Model
{
    use HasFactory;

    protected $table = 'report_exports';

    protected $fillable = [
        'type',
        'status',
        'filters',
        'output_path',
        'started_at',
        'finished_at',
        'failed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'status' => ReportExportStatus::class,
            'filters' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function markAsProcessing(): self
    {
        $this->forceFill([
            'status' => ReportExportStatus::Processing,
            'started_at' => $this->started_at ?? now(),
            'failed_at' => null,
            'error_message' => null,
        ])->save();

        return $this;
    }

    public function markAsFinished(string $outputPath): self
    {
        $this->forceFill([
            'status' => ReportExportStatus::Finished,
            'output_path' => $outputPath,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function markAsFailed(string $message): self
    {
        $this->forceFill([
            'status' => ReportExportStatus::Failed,
            'failed_at' => now(),
            'error_message' => $message,
        ])->save();

        return $this;
    }
}
