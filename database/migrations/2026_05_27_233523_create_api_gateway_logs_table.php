<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_gateway_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('log_import_id')
                ->constrained('log_imports')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('line_number');
            $table->unsignedBigInteger('byte_offset');

            $table->char('consumer_id', 36)->nullable()->index();
            $table->char('service_id', 36)->nullable();
            $table->string('service_name')->nullable()->index();

            $table->string('request_method', 10)->nullable();
            $table->text('request_uri')->nullable();

            $table->unsignedSmallInteger('response_status')->nullable();

            $table->unsignedInteger('latency_request')->nullable();
            $table->unsignedInteger('latency_proxy')->nullable();
            $table->unsignedInteger('latency_gateway')->nullable();

            /*
             * started_at:
             * Data/hora original em que a requisição ocorreu no API Gateway.
             *
             * created_at:
             * Timestamp de geração do log.
             * Neste projeto, ele NÃO representa o momento de insert do Laravel.
             *
             * processed_at:
             * Momento em que o sistema processou/inseriu o registro no banco.
             */
            $table->dateTime('started_at', 3)->nullable()->index();
            $table->dateTime('created_at', 3)->nullable()->index();
            $table->dateTime('processed_at', 3)->index();

            $table->json('raw_payload');

            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(
                ['log_import_id', 'line_number'],
                'api_gateway_logs_import_line_unique'
            );

            $table->index(['service_name', 'latency_request']);
            $table->index(['service_name', 'latency_proxy']);
            $table->index(['service_name', 'latency_gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_gateway_logs');
    }
};
