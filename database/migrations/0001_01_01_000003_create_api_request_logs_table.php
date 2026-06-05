<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 500);
            $table->string('method', 10);
            $table->unsignedSmallInteger('status_code');
            $table->string('ip_address', 45);
            $table->timestamp('requested_at');
            $table->timestamps();

            // Indexes for query optimization
            $table->index('requested_at');
            $table->index(['endpoint', 'requested_at']);
            $table->index('ip_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
