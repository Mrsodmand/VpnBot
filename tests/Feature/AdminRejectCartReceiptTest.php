<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TelegramBotController;
use App\Models\Payment;
use App\Services\Telegram;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestCase;

class AdminRejectCartReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['TELEGRAM_BOT_TOKEN'] = 'test-token';
        $_SERVER['TELEGRAM_BOT_TOKEN'] = 'test-token';
        putenv('TELEGRAM_BOT_TOKEN=test-token');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('tel_id')->unique();
            $table->string('is_admin')->default('0');
            $table->string('is_seller')->default('0');
            $table->string('path')->default('start');
            $table->string('username')->nullable();
            $table->string('parent')->default('0');
            $table->integer('balance')->default(0);
            $table->integer('status')->default(1);
            $table->timestamps();
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

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('name')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_data', function (Blueprint $table) {
            $table->id();
            $table->string('tel_id')->nullable();
            $table->json('data')->nullable();
            $table->text('path')->nullable();
            $table->text('types')->nullable();
            $table->timestamps();
        });
    }

    public function test_admin_can_reject_a_pending_receipt_from_a_channel_callback(): void
    {
        DB::table('users')->insert([
            [
                'id' => 1,
                'tel_id' => '1001',
                'first_name' => 'Admin',
                'username' => 'admin',
                'is_admin' => '1',
                'is_seller' => '0',
                'path' => 'start',
                'status' => 1,
            ],
            [
                'id' => 2,
                'tel_id' => '2002',
                'first_name' => 'Customer',
                'username' => 'customer',
                'is_admin' => '0',
                'is_seller' => '0',
                'path' => 'start',
                'status' => 1,
            ],
        ]);

        DB::table('payments')->insert([
            'id' => 9475,
            'user_id' => 2,
            'method' => 'cart-be-cart',
            'type' => '1',
            'price' => 600000,
            'status' => 0,
            'detail' => json_encode([
                'cart-number' => '6219861437345936',
                'cart-name' => 'ضیایی',
            ]),
        ]);

        $channelId = -1001234567890;
        $request = Request::create('/api/telegram-webhook', 'POST', [
            'callback_query' => [
                'id' => 'callback-1',
                'from' => [
                    'id' => 1001,
                    'first_name' => 'Admin',
                    'username' => 'admin',
                ],
                'message' => [
                    'message_id' => 77,
                    'chat' => ['id' => $channelId, 'type' => 'channel'],
                    'photo' => [['file_id' => 'receipt']],
                    'caption' => 'رسید جدید کارت به کارت',
                ],
                'data' => 'type=adminRejectCartReceipt|p_id=9475',
            ],
        ]);

        $controller = new TelegramBotController($request);
        $telegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($controller, 'telegramSdk');
        $telegramProperty->setValue($controller, $telegram);

        $controller->index();

        $payment = Payment::findOrFail(9475);
        $this->assertSame(-1, (int) $payment->status);
        $this->assertSame(1, (int) $payment->admin_id);
        $this->assertFalse(DB::table('users')->where('tel_id', (string) $channelId)->exists());

        $this->assertSame('callback-1', $telegram->answeredCallbacks[0]['callback_query_id']);
        $this->assertSame($channelId, $telegram->editedCaptions[0]['chat_id']);
        $this->assertStringContainsString('تراکنش کارت به کارت رد شد', $telegram->editedCaptions[0]['caption']);
        $this->assertSame(['inline_keyboard' => []], json_decode($telegram->editedCaptions[0]['reply_markup'], true));
        $this->assertSame('2002', (string) $telegram->sentMessages[0]['chat_id']);
        $this->assertStringContainsString('پرداخت شما تایید نشد', $telegram->sentMessages[0]['text']);
    }

    public function test_non_admin_cannot_reject_a_receipt_from_the_channel(): void
    {
        DB::table('users')->insert([
            'id' => 2,
            'tel_id' => '2002',
            'first_name' => 'Customer',
            'is_admin' => '0',
            'is_seller' => '0',
            'path' => 'start',
            'status' => 1,
        ]);

        DB::table('payments')->insert([
            'id' => 9475,
            'user_id' => 2,
            'method' => 'cart-be-cart',
            'type' => '1',
            'price' => 600000,
            'status' => 0,
            'detail' => json_encode([]),
        ]);

        $request = Request::create('/api/telegram-webhook', 'POST', [
            'callback_query' => [
                'id' => 'callback-2',
                'from' => ['id' => 2002, 'first_name' => 'Customer'],
                'message' => [
                    'message_id' => 77,
                    'chat' => ['id' => -1001234567890, 'type' => 'channel'],
                    'text' => 'رسید جدید کارت به کارت',
                ],
                'data' => 'type=adminRejectCartReceipt|p_id=9475',
            ],
        ]);

        $controller = new TelegramBotController($request);
        $telegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($controller, 'telegramSdk');
        $telegramProperty->setValue($controller, $telegram);

        $controller->index();

        $payment = Payment::findOrFail(9475);
        $this->assertSame(0, (int) $payment->status);
        $this->assertNull($payment->admin_id);
        $this->assertSame('دسترسی غیرمجاز است.', $telegram->answeredCallbacks[0]['text']);
        $this->assertSame([], $telegram->editedCaptions);
        $this->assertSame([], $telegram->sentMessages);
    }
}

class FakeTelegramForReceiptRejection extends Telegram
{
    public array $answeredCallbacks = [];
    public array $editedCaptions = [];
    public array $sentMessages = [];

    public function __construct()
    {
    }

    public function answerCallback(array $params)
    {
        $this->answeredCallbacks[] = $params;

        return ['ok' => true];
    }

    public function editCaption(array $params)
    {
        $this->editedCaptions[] = $params;

        return ['ok' => true];
    }

    public function sendMessage(array $params)
    {
        $this->sentMessages[] = $params;

        return ['ok' => true, 'result' => ['message_id' => 1]];
    }
}
