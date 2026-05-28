<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

final class LogImportError extends Model
{
    use HasFactory;

    protected $table = 'log_import_errors';

    protected $fillable = [
        'log_import_id',
        'line_number',
        'byte_offset',
        'error_message',
        'raw_line',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'byte_offset' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(LogImport::class, 'log_import_id');
    }

    public static function makeInsertPayload(
        int $logImportId,
        int $lineNumber,
        int $byteOffset,
        string $rawLine,
        Throwable $exception,
        CarbonInterface $createdAt,
    ): array {
        return [
            'log_import_id' => $logImportId,
            'line_number' => $lineNumber,
            'byte_offset' => $byteOffset,
            'error_message' => $exception->getMessage(),
            'raw_line' => $rawLine,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }
}
