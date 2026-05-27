<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_imports', function (Blueprint $table): void {
            $table->id();

            $table->string('file_path', 1000);
            $table->char('file_hash', 64)->unique();

            $table->string('status', 30)->default('queued')->index();

            $table->unsignedBigInteger('current_offset')->default(0);
            $table->unsignedBigInteger('last_line_number')->default(0);

            $table->unsignedBigInteger('total_lines_processed')->default(0);
            $table->unsignedBigInteger('total_lines_failed')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_imports');
    }
};
