<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\GatewayLog\Enums\LogImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LogImport extends Model
{
    use HasFactory;

    protected $table = 'log_imports';

    protected $fillable = [
        'file_path',
        'file_hash',
        'status',
        'current_offset',
        'last_line_number',
        'total_lines_processed',
        'total_lines_failed',
        'started_at',
        'finished_at',
        'failed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => LogImportStatus::class,
            'current_offset' => 'integer',
            'last_line_number' => 'integer',
            'total_lines_processed' => 'integer',
            'total_lines_failed' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function apiGatewayLogs(): HasMany
    {
        return $this->hasMany(ApiGatewayLog::class, 'log_import_id');
    }

    public function importErrors(): HasMany
    {
        return $this->hasMany(LogImportError::class, 'log_import_id');
    }

    public function markAsProcessing(): self
    {
        $this->forceFill([
            'status' => LogImportStatus::Processing,
            'started_at' => $this->started_at ?? now(),
            'failed_at' => null,
            'error_message' => null,
        ])->save();

        return $this;
    }

    public function markAsFinished(): self
    {
        $this->forceFill([
            'status' => LogImportStatus::Finished,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function markAsFailed(string $message): self
    {
        $this->forceFill([
            'status' => LogImportStatus::Failed,
            'failed_at' => now(),
            'error_message' => $message,
        ])->save();

        return $this;
    }

    public function isFinished(): bool
    {
        return $this->status === LogImportStatus::Finished;
    }
}
