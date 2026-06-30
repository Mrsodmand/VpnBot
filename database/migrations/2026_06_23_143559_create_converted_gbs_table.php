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
        Schema::create('converted_gbs', function (Blueprint $table) {
            $table->id();
            $table->string('tel_id')->nullable();
            $table->string('mobile')->nullable();
            $table->string('order_id')->nullable();
            $table->string('uid')->unique();
            $table->string('gb')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('converted_gbs');
    }
};
