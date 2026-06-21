<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wp_sync_site_orders')) return;
        Schema::create('wp_sync_site_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_order_id')->unique();
            $table->unsignedBigInteger('bot_order_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('order_code')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_sync_site_orders');
    }
};
