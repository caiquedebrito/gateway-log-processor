<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_gateway_logs', function (Blueprint $table): void {
            $table
                ->char('event_hash', 64)
                ->after('log_import_id');

            $table->unique('event_hash', 'api_gateway_logs_event_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('api_gateway_logs', function (Blueprint $table): void {
            $table->dropUnique('api_gateway_logs_event_hash_unique');
            $table->dropColumn('event_hash');
        });
    }
};
