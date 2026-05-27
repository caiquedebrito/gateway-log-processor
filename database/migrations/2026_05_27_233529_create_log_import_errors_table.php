<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_import_errors', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('log_import_id')
                ->constrained('log_imports')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('line_number');
            $table->unsignedBigInteger('byte_offset');

            $table->text('error_message');
            $table->longText('raw_line')->nullable();

            $table->timestamps();

            $table->index(['log_import_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_import_errors');
    }
};
