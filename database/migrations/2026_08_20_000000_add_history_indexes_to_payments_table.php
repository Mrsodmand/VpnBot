<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['user_id', 'order_id', 'type'], 'payments_user_order_type_idx');
            $table->index(['user_id', 'method', 'status'], 'payments_user_method_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_user_order_type_idx');
            $table->dropIndex('payments_user_method_status_idx');
        });
    }
};
