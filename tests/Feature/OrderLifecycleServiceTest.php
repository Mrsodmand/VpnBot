<?php

namespace Tests\Feature;

use App\Models\Orders;
use App\Services\OrderLifecycleService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderLifecycleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status');
            $table->json('detail')->nullable();
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();
        });

        Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expired_orders_are_suspended_for_seven_days_then_become_inactive(): void
    {
        $renewable = $this->createOrder(1, '1', now()->subDays(6)->subHours(23));
        $graceEnded = $this->createOrder(1, '1', now()->subDays(7));
        $future = $this->createOrder(1, '1', now()->addDay());
        $otherUser = $this->createOrder(2, '1', now()->subDays(8));

        $lifecycle = app(OrderLifecycleService::class);
        $lifecycle->reconcileTimeStatuses(1);

        $this->assertSame(Orders::STATUS_SUSPENDED, $renewable->fresh()->status);
        $this->assertTrue($lifecycle->canRenew($renewable->fresh()));
        $this->assertSame(Orders::STATUS_INACTIVE, $graceEnded->fresh()->status);
        $this->assertFalse($lifecycle->canRenew($graceEnded->fresh()));
        $this->assertSame(Orders::STATUS_ACTIVE, $lifecycle->statusMeta($future->fresh())['key']);
        $this->assertSame('1', $otherUser->fresh()->status);
    }

    public function test_orders_are_sorted_by_business_status_then_newest_id(): void
    {
        $inactive = $this->createOrder(1, Orders::STATUS_INACTIVE, now()->addDay());
        $suspended = $this->createOrder(1, Orders::STATUS_SUSPENDED, now()->subDay());
        $exhausted = $this->createOrder(1, Orders::STATUS_DATA_EXHAUSTED, now()->addDay());
        $activeOlder = $this->createOrder(1, Orders::STATUS_ACTIVE, now()->addDay());
        $activeNewer = $this->createOrder(1, Orders::STATUS_ACTIVE, now()->addDay());

        $lifecycle = app(OrderLifecycleService::class);
        $query = $lifecycle->orderByStatus(Orders::query())->orderByDesc('id');

        $this->assertSame('🟢', $lifecycle->statusMeta($activeOlder)['icon']);
        $this->assertSame('🟠', $lifecycle->statusMeta($exhausted)['icon']);
        $this->assertSame('🔵', $lifecycle->statusMeta($suspended)['icon']);
        $this->assertSame('🔴', $lifecycle->statusMeta($inactive)['icon']);
        $this->assertTrue($lifecycle->canBuyExtra($exhausted));
        $this->assertFalse($lifecycle->canBuyExtra($suspended));

        $this->assertSame([
            $activeNewer->id,
            $activeOlder->id,
            $exhausted->id,
            $suspended->id,
            $inactive->id,
        ], $query->pluck('id')->all());
    }

    private function createOrder(int $userId, string $status, Carbon $expireAt): Orders
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'status' => $status,
            'detail' => '{}',
            'expire_at' => $expireAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Orders::findOrFail($id);
    }
}
