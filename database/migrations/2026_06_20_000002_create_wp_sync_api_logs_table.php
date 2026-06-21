<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wp_sync_api_logs')) return;
        Schema::create('wp_sync_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint')->nullable()->index();
            $table->string('status')->nullable()->index();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_sync_api_logs');
    }
};
