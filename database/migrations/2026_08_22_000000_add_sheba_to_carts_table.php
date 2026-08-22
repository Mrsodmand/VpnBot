<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('carts', 'sheba')) {
            return;
        }

        Schema::table('carts', function (Blueprint $table) {
            $table->string('sheba')->nullable()->after('cart');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('carts', 'sheba')) {
            return;
        }

        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('sheba');
        });
    }
};
