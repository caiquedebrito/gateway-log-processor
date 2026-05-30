<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\GatewayLog\DTO\GatewayLogData;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ApiGatewayLog extends Model
{
    use HasFactory;

    protected $table = 'api_gateway_logs';

    public $timestamps = false;

    protected $fillable = [
        'log_import_id',
        'event_hash',
        'line_number',
        'byte_offset',
        'consumer_id',
        'service_id',
        'service_name',
        'request_method',
        'request_uri',
        'response_status',
        'latency_request',
        'latency_proxy',
        'latency_gateway',
        'started_at',
        'created_at',
        'processed_at',
        'raw_payload',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'byte_offset' => 'integer',
            'response_status' => 'integer',
            'latency_request' => 'integer',
            'latency_proxy' => 'integer',
            'latency_gateway' => 'integer',
            'started_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'raw_payload' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(LogImport::class, 'log_import_id');
    }

    public static function makeInsertPayload(
        int $logImportId,
        GatewayLogData $data,
        string $eventHash,
        CarbonInterface $processedAt,
    ): array {
        return [
            'log_import_id' => $logImportId,
            'event_hash' => $eventHash,
            'line_number' => $data->lineNumber,
            'byte_offset' => $data->byteOffset,
            'consumer_id' => $data->consumerId,
            'service_id' => $data->serviceId,
            'service_name' => $data->serviceName,
            'request_method' => $data->requestMethod,
            'request_uri' => $data->requestUri,
            'response_status' => $data->responseStatus,
            'latency_request' => $data->latencies->request,
            'latency_proxy' => $data->latencies->proxy,
            'latency_gateway' => $data->latencies->gateway,
            'started_at' => $data->startedAt,
            'created_at' => $data->createdAt,
            'processed_at' => $processedAt,
            'raw_payload' => json_encode($data->rawPayload, JSON_THROW_ON_ERROR),
            'updated_at' => $processedAt,
        ];
    }
}
