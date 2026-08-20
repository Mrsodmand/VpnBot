<?php

namespace Tests\Feature;

use App\Models\Orders;
use App\Services\OrderCountryResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderCountryResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('pre_orders', function (Blueprint $table) {
            $table->id();
            $table->text('data')->nullable();
        });
        Schema::create('panels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id')->nullable();
        });
        Schema::create('inbounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('panel_id')->nullable();
            $table->unsignedBigInteger('inbound_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('panel_id')->nullable();
            $table->unsignedBigInteger('inbound_id')->nullable();
            $table->string('system_type')->nullable();
            $table->json('detail')->nullable();
        });
    }

    public function test_it_resolves_every_supported_order_country_source(): void
    {
        DB::table('countries')->insert([
            ['id' => 1, 'name' => '🇬🇧 انگلیس'],
            ['id' => 2, 'name' => '🇩🇪 آلمان'],
            ['id' => 3, 'name' => '🇳🇱 هلند'],
            ['id' => 4, 'name' => '🇶🇦 قطر'],
        ]);
        DB::table('pre_orders')->insert([
            ['id' => 10, 'data' => json_encode(['country-id' => 1])],
            ['id' => 11, 'data' => json_encode(['country-id' => 0, 'pasarguard-id' => 9])],
        ]);
        DB::table('panels')->insert([
            ['id' => 30, 'country_id' => 3],
            ['id' => 31, 'country_id' => null],
            ['id' => 32, 'country_id' => 4],
        ]);
        DB::table('inbounds')->insert([
            ['id' => 20, 'panel_id' => 31, 'inbound_id' => 900, 'country_id' => 2],
            ['id' => 21, 'panel_id' => 31, 'inbound_id' => 901, 'country_id' => 1],
        ]);
        DB::table('orders')->insert([
            ['id' => 101, 'panel_id' => null, 'inbound_id' => null, 'detail' => json_encode(['country' => '🇫🇷 فرانسه'])],
            ['id' => 102, 'panel_id' => null, 'inbound_id' => null, 'detail' => json_encode(['preOrderId' => 10])],
            ['id' => 103, 'panel_id' => 31, 'inbound_id' => 20, 'detail' => '{}'],
            ['id' => 104, 'panel_id' => 30, 'inbound_id' => null, 'detail' => '{}'],
            ['id' => 105, 'panel_id' => null, 'inbound_id' => null, 'detail' => json_encode(['preOrderId' => 11])],
            ['id' => 106, 'panel_id' => 31, 'inbound_id' => 901, 'detail' => '{}'],
            ['id' => 107, 'panel_id' => null, 'inbound_id' => null, 'detail' => '{}'],
            ['id' => 108, 'panel_id' => null, 'inbound_id' => null, 'detail' => json_encode(['raw' => ['country_flag' => '🇮🇹', 'country_name' => 'ایتالیا']])],
            // An explicit all-country pre-order must override a stale direct country name.
            ['id' => 110, 'panel_id' => 32, 'inbound_id' => null, 'detail' => json_encode(['country' => '🇶🇦 قطر', 'preOrderId' => 11])],
        ]);
        // Legacy all-country orders used the PasarGuard panel country as a fallback.
        DB::table('orders')->insert([
            'id' => 109,
            'panel_id' => 32,
            'inbound_id' => 0,
            'system_type' => 'pasarguard',
            'detail' => '{}',
        ]);

        $countries = app(OrderCountryResolver::class)->resolve(Orders::orderBy('id')->get());

        $this->assertSame('🇫🇷 فرانسه', $countries[101]);
        $this->assertSame('🇬🇧 انگلیس', $countries[102]);
        $this->assertSame('🇩🇪 آلمان', $countries[103]);
        $this->assertSame('🇳🇱 هلند', $countries[104]);
        $this->assertSame('🌍 همه کشورها', $countries[105]);
        $this->assertSame('🇬🇧 انگلیس', $countries[106]);
        $this->assertSame('🌍 نامشخص', $countries[107]);
        $this->assertSame('🇮🇹 ایتالیا', $countries[108]);
        $this->assertSame('🌍 همه کشورها', $countries[109]);
        $this->assertSame('🌍 همه کشورها', $countries[110]);
    }
}
