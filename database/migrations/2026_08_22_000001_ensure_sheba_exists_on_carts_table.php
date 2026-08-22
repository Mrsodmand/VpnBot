<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('carts', 'sheba')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->string('sheba')->nullable()->after('cart');
            });
        }
    }

    public function down(): void
    {
        // The original sheba migration owns this column. This repair migration
        // intentionally leaves it intact when rolling back only this migration.
    }
};
