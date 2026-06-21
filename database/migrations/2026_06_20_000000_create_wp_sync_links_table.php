<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wp_sync_links')) return;
        Schema::create('wp_sync_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('tel_id')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->unsignedBigInteger('site_user_id')->nullable()->index();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
            $table->unique(['tel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_sync_links');
    }
};
