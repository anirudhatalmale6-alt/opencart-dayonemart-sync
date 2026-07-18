<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letsync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('entity', 30);
            $table->unsignedBigInteger('external_id')->nullable();
            $table->string('event', 40);
            $table->string('status', 20);
            $table->unsignedBigInteger('local_id')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity', 'external_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letsync_logs');
    }
};
