<?php

namespace Tests\Feature;

use App\Models\Orders;
use App\Models\Payment;
use App\Models\User;
use App\Services\WpSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('tel_id')->nullable();
            $table->string('username')->nullable();
            $table->integer('balance')->default(0);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->json('detail')->nullable();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('method')->nullable();
            $table->string('ref_id')->nullable();
            $table->string('type')->nullable();
            $table->integer('price')->nullable();
            $table->integer('status')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_order_and_wallet_histories_are_scoped_and_admin_adjustments_are_recorded(): void
    {
        DB::table('users')->insert([
            ['id' => 1, 'tel_id' => '1001', 'username' => 'client', 'balance' => 1000],
            ['id' => 2, 'tel_id' => '1002', 'username' => 'admin', 'balance' => 0],
        ]);
        DB::table('orders')->insert([
            'id' => 50,
            'user_id' => 1,
            'detail' => json_encode(['preOrderId' => 900]),
        ]);
        DB::table('payments')->insert([
            ['id' => 1, 'user_id' => 1, 'order_id' => 900, 'method' => 'cart-be-cart', 'type' => '1', 'price' => 500, 'status' => 1],
            ['id' => 2, 'user_id' => 1, 'order_id' => 50, 'method' => 'cart-be-cart', 'type' => '2', 'price' => 200, 'status' => 1],
            ['id' => 3, 'user_id' => 1, 'order_id' => 50, 'method' => 'wallet', 'type' => '3', 'price' => 100, 'status' => 1],
            ['id' => 4, 'user_id' => 2, 'order_id' => 50, 'method' => 'wallet', 'type' => '2', 'price' => 999, 'status' => 1],
            ['id' => 5, 'user_id' => 1, 'order_id' => 0, 'method' => 'cart-be-cart', 'type' => '4', 'price' => 400, 'status' => 1],
            ['id' => 6, 'user_id' => 1, 'order_id' => 0, 'method' => 'cart-be-cart', 'type' => '2', 'price' => 300, 'status' => 1],
        ]);

        $order = Orders::findOrFail(50);
        $user = User::findOrFail(1);
        $admin = User::findOrFail(2);

        $this->assertSame([1, 2, 3], Payment::forOrderHistory($order)->orderBy('id')->pluck('id')->all());
        $this->assertSame([3, 5], Payment::forWalletHistory($user)->orderBy('id')->pluck('id')->all());

        $service = app(WpSyncService::class);
        $credit = $service->adminWalletAdjust($user, 'credit', 250, '', ['admin_id' => $admin->id]);
        $debit = $service->adminWalletAdjust($user->fresh(), 'debit', 100, '', ['admin_id' => $admin->id]);
        $rejectedDebit = $service->adminWalletAdjust($user->fresh(), 'debit', 5000, '', ['admin_id' => $admin->id]);

        $this->assertTrue($credit['ok']);
        $this->assertTrue($debit['ok']);
        $this->assertFalse($rejectedDebit['ok']);
        $this->assertSame(1150, (int) $user->fresh()->balance);
        $this->assertSame(['admin_credit', 'admin_debit'], Payment::whereIn('method', ['admin_credit', 'admin_debit'])->orderBy('id')->pluck('method')->all());
    }
}
