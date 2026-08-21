<?php

namespace Tests\Feature;

use App\Models\Orders;
use App\Services\OrderLifecycleNotificationService;
use App\Services\OrderLifecycleService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderLifecycleNotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('tel_id')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('name')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('remark');
            $table->string('status');
            $table->integer('reminded')->default(0);
            $table->json('detail')->nullable();
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert([
            'id' => 1,
            'tel_id' => '1001',
            'status' => 1,
        ]);
        DB::table('settings')->insert([
            ['key' => 'renew', 'name' => 'Renew', 'value' => '1'],
            ['key' => 'extra', 'name' => 'Extra', 'value' => '1'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_sends_renewal_and_low_volume_notifications_once_per_order_cycle(): void
    {
        $renewalOrder = $this->createOrder(
            'office-service',
            now()->addHours(24),
            ['total_gb' => 20, 'used_gb' => 15, 'left_gb' => 5]
        );
        $volumeOrder = $this->createOrder(
            'mobile-service',
            now()->addDays(5),
            ['total_gb' => 10, 'used_gb' => 9, 'left_gb' => 1]
        );
        $safeOrder = $this->createOrder(
            'safe-service',
            now()->addHours(25),
            ['total_gb' => 30, 'used_gb' => 10, 'left_gb' => 20]
        );

        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $first = $notifications->sendDueNotifications();

        $this->assertSame(1, $first['renewal_sent']);
        $this->assertSame(1, $first['volume_sent']);
        $this->assertCount(2, $notifications->messages);
        $this->assertStringContainsString('زمان تمدید سرویس نزدیک است', $notifications->messages[0]['text']);
        $this->assertStringContainsString("type=clientRenewOrder|id={$renewalOrder->id}", $notifications->messages[0]['reply_markup']);
        $this->assertStringContainsString('حجم سرویس رو به اتمام است', $notifications->messages[1]['text']);
        $this->assertStringContainsString("type=clientBuyExtra|id={$volumeOrder->id}", $notifications->messages[1]['reply_markup']);
        $this->assertSame(1, (int) $renewalOrder->fresh()->reminded);
        $this->assertSame(0, (int) $safeOrder->fresh()->reminded);

        $second = $notifications->sendDueNotifications();

        $this->assertSame(0, $second['renewal_sent']);
        $this->assertSame(0, $second['volume_sent']);
        $this->assertCount(2, $notifications->messages);

        $renewalState = $renewalOrder->fresh()->detail['lifecycle']['notifications']['renewal_24h'];
        $volumeState = $volumeOrder->fresh()->detail['lifecycle']['notifications']['low_volume'];
        $this->assertSame('sent', $renewalState['state']);
        $this->assertSame('sent', $volumeState['state']);
    }

    public function test_a_failed_delivery_is_released_and_retried(): void
    {
        $order = $this->createOrder(
            'retry-service',
            now()->addHours(12),
            ['total_gb' => 20, 'used_gb' => 10, 'left_gb' => 10]
        );

        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $notifications->failNext = true;
        $failed = $notifications->sendDueNotifications();

        $this->assertSame(1, $failed['failed']);
        $this->assertArrayNotHasKey('renewal_24h', $order->fresh()->detail['lifecycle']['notifications']);

        $retried = $notifications->sendDueNotifications();

        $this->assertSame(1, $retried['renewal_sent']);
        $this->assertSame('sent', $order->fresh()->detail['lifecycle']['notifications']['renewal_24h']['state']);
    }

    private function createOrder(string $remark, Carbon $expireAt, array $lifecycle): Orders
    {
        $lifecycle['last_checked_at'] = now()->toDateTimeString();
        $id = DB::table('orders')->insertGetId([
            'user_id' => 1,
            'remark' => $remark,
            'status' => Orders::STATUS_ACTIVE,
            'reminded' => 0,
            'detail' => json_encode(['lifecycle' => $lifecycle]),
            'expire_at' => $expireAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Orders::findOrFail($id);
    }
}

class FakeOrderLifecycleNotificationService extends OrderLifecycleNotificationService
{
    public array $messages = [];

    public bool $failNext = false;

    protected function sendTelegramMessage(array $params): array
    {
        $this->messages[] = $params;

        if ($this->failNext) {
            $this->failNext = false;

            return ['ok' => false, 'error' => 'temporary_failure'];
        }

        return ['ok' => true];
    }
}
