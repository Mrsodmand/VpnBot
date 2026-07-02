<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Lib\PasarGuard;
use App\Models\HegzaIp;
use App\Models\HegzaMessage;
use App\Models\HegzaUser;
use App\Models\Message;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Setting;
use App\Models\TelegramData;
use App\Models\User;
use App\Services\Telegram;
use Carbon\Carbon;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function sendBulkMessage()
    {
        $message = Message::where('status', 0)->first();
        $second = 0;

        if (!is_null($message)) {

            $users = User::whereNotNull('tel_id')
                ->where(function ($query) {
                    $query->whereNull('tel_detail')
                        ->orWhereNull('tel_detail->message')
                        ->orWhere('tel_detail->message', '!=', 1);
                })
                ->select('id', 'tel_id', 'tel_detail')
                ->take(100)
                ->get();

            $detail = json_decode($message->detail, true);

            $telegramSdk = new Telegram(env('TELEGRAM_BOT_TOKEN'));

            if (count($users) != 0) {
                $keyboard['inline_keyboard'][][] = ['text' => '🔙 بازگشت', 'callback_data' => 'type=home'];
                foreach ($users as $item) {
                    $second = $second + 1;

                    if ($second <= 15) {
                        $item->update([
                            'tel_detail->message' => 0,
                        ]);

                        switch ($message->type) {
                            case 'text':
                                $data = [
                                    'chat_id' => $item->tel_id,
                                    'text' => $detail['text'],
                                    'reply_markup' => json_encode($keyboard),
                                    'parse_mode' => 'HTML'
                                ];
                                $telegramSdk->sendMessage($data);
                                break;

                            case 'cover':
                                $data = [
                                    'chat_id' => $item->tel_id,
                                    'photo' => url($detail['img']),
                                    'caption' => $detail['text'],
                                    'parse_mode' => 'HTML',
                                    'reply_markup' => json_encode($keyboard),
                                ];
                                $telegramSdk->sendPhoto($data);
                                break;

                            case 'forward':

                                $data = [
                                    'chat_id' => $item->tel_id,
                                    'from_chat_id' => 'asd',
                                    'message_id' => 'asd',
                                ];
                                $telegramSdk->forwardMessage($data);
                                break;
                        }

                        sleep(1);
                    } else {
                        $second = 0;
                        sleep(2);
                    }
                }
            } else {
                User::query()->update([
                    'tel_detail->message' => 0,
                ]);
                $message->status = 1;
                $message->save();
            }
        }
    }


    public function checkUserBw()
    {
        $setting = Setting::where('key', 'alert-bw')->first();
        $gb = !is_null($setting) ? $setting->value : 1024;
        $thresholdBytes = $gb * 1024 * 1024;
        $Orders = Orders::where('bw_reminded', 0)->take(100)->get();

        if (count($Orders) > 0) {
            foreach ($Orders as $order) {
                $panel = Panels::find($order->panel_id);
                $pasarGuard = new PasarGuard([
                    'id' => $panel->id,
                    'url' => $panel->url,
                    'username' => $panel->username,
                    'password' => $panel->password,
                ]);

                if (!$pasarGuard->checkConnection()) {
                    Log::error('PasarGuard login failed', [
                        'panel_id' => $panel->id,
                        'login' => $pasarGuard->getLoginStatus(),
                    ]);
                    continue;
                }

                $pgUser = $pasarGuard->getUserById($order->uid);

                if (!is_array($pgUser) || isset($pgUser['status']) && $pgUser['status'] === false) {
                    $order->bw_reminded = -2;
                    $order->save();
                    continue;
                }

                $dataLimit = (int)($pgUser['data_limit'] ?? 0);
                $usedTraffic = (int)($pgUser['used_traffic'] ?? 0);

                if ($dataLimit <= 0) {
                    continue;
                }

                $remainingBytes = $dataLimit - $usedTraffic;
                $remainingMb = round(max($remainingBytes, 0) / 1024 / 1024, 2);

                if ($remainingBytes > $thresholdBytes) {
                    $order->bw_reminded = -1;
                    $order->save();
                    continue;
                }
                $this->sendLowVolumeMessage($order, $pgUser, $remainingMb, $gb);
                $order->bw_reminded = 1;
                $order->save();
            }
        } else {
            Orders::where('bw_reminded', -1)->update(['bw_reminded' => 0]);
        }
    }

    private function sendLowVolumeMessage($order, array $pgUser, float $remainingMb, int $gb): void
    {

        $chatId = User::where('id', $order->user_id)->first()->tel_id;

        if (empty($chatId)) {
            return;
        }
        $username = $pgUser['username'] ?? $order->uid;

        $text = "⚠️ <b>هشدار اتمام حجم سرویس</b>\n\n";
        $text .= "کاربر عزیز، حجم باقی‌مانده سرویس شما کمتر از {$gb} مگابایت است.\n\n";
        $text .= "👤 سرویس: <code>{$username}</code>\n";
        $text .= "📦 حجم باقی‌مانده: <b>{$remainingMb} MB</b>\n\n";
        $text .= "برای جلوگیری از قطعی سرویس، لطفا نسبت به تمدید یا خرید حجم اقدام کنید.";
        $telegram = new Telegram(env('TELEGRAM_BOT_TOKEN'));
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄 تمدید سفارش',
                            'callback_data' => "type=clientSingleOrder|id={$order->id}",
                        ]
                    ]
                ]
            ]),
        ]);

    }

    public function remindToRenewOrder()
    {
        $setting = Setting::where('key', 'alert-time')->first();

        $day = !is_null($setting) ? (int)$setting->value : 2;

        $today = Carbon::today();
        $startDate = Carbon::parse('2026-07-02');
        /*
         * فقط سفارش‌هایی که:
         * - هنوز بررسی نشده‌اند
         * - تاریخ انقضا دارند
         * - از دیروز تا چند روز آینده هستند
         */
        $orders = Orders::whereNotNull('expire_at')
            ->where('created_at', '>', $startDate)
            ->where('user_id', 1)
            ->where('reminded', 0)
            ->whereBetween('expire_at', [
                $today->copy()->subDay()->startOfDay(),
                $today->copy()->addDays($day)->endOfDay(),
            ])
            ->take(100)
            ->get();

        foreach ($orders as $order) {

            $expireAt = Carbon::parse($order->expire_at)->startOfDay();

            /*
             * اگر تاریخ انقضا آینده باشد: عدد مثبت
             * اگر امروز باشد: 0
             * اگر دیروز گذشته باشد: -1
             */
            $remainingDays = $today->diffInDays($expireAt, false);

            /*
             * اگر یک روز از انقضا گذشته باشد
             */
            if ($remainingDays == -1) {
                $order->reminded = 2;
                $order->save();
                continue;
            }

            /*
             * اگر تعداد روز باقی‌مانده کمتر یا مساوی مقدار تنظیمات بود
             */
            if ($remainingDays >= 0 && $remainingDays <= $day) {

                // اینجا پیام تمدید را ارسال کن
                $this->sendRenewReminderMessage($order, $remainingDays);

                $order->reminded = 1;
                $order->save();
            }
        }

        return true;
    }

    private function sendRenewReminderMessage($order, int $remainingDays)
    {
        $chatId = User::where('id', $order->user_id)->first()->tel_id;
        $text = "⏰ یادآوری تمدید سرویس\n\n";
        $text .= "سفارش شماره:{$order->id}\n\n";

        if ($remainingDays == 0) {
            $text .= "کاربر عزیز، سرویس شما امروز منقضی می‌شود.\n";
        } else {
            $text .= "کاربر عزیز، فقط {$remainingDays} روز تا پایان سرویس شما باقی مانده است.\n";
        }
        $text .= "\nبرای جلوگیری از قطعی سرویس، لطفاً نسبت به تمدید اقدام کنید.";

        $telegram = new Telegram(env('TELEGRAM_BOT_TOKEN'));
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄 تمدید سفارش',
                            'callback_data' => "type=clientSingleOrder|id={$order->id}",
                        ]
                    ]
                ]
            ]),
        ]);
    }


    public function expireOrders()
    {
        $now = Carbon::now();

        $startDate = Carbon::parse('2026-07-02');

        $orders = Orders::whereNotNull('expire_at')
            ->where('created_at', '>', $startDate)
            ->where('user_id', 1)
            ->where('reminded', '!=', 2)
            ->where('expire_at', '<=', $now)
            ->take(100)
            ->get();

        foreach ($orders as $order) {

            // لغو سفارش
            $order->status = 'failed';
            $order->reminded = 2;
            $order->save();

            $this->sendExpiredOrderMessage($order);
        }

        return true;
    }

    private function sendExpiredOrderMessage($order)
    {
        $text = "❌ <b>سرویس شما منقضی شد</b>\n\n";
        $text .= "سفارش شماره:{$order->id}\n\n";
        $text .= "کاربر عزیز، زمان سرویس شما به پایان رسیده و سفارش شما لغو شد.\n\n";
        $text .= "برای استفاده مجدد، لطفاً نسبت به تمدید یا خرید سرویس جدید اقدام کنید.";

        $chatId = User::where('id', $order->user_id)->first()->tel_id;

        if (empty($chatId) && !empty($order->user?->tel_id)) {
            $chatId = $order->user->tel_id;
        }

        if (empty($chatId)) {
            return false;
        }

        $telegram = new Telegram(env('TELEGRAM_BOT_TOKEN'));
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄 تمدید سفارش',
                            'callback_data' => "type=clientSingleOrder|id={$order->id}",
                        ]
                    ]
                ]
            ]),
        ]);
        return true;
    }

    public function ramzinoFailCallback(Request $request)
    {
        $data = $request->all();
        $orderId = $data['order_id'];

        $telData = new TelegramData();
        $telData->data = json_encode($data);
        $telData->path = 'fail-crypto';
        $telData->save();
    }

    public function ramzinoSuccessCallback(Request $request)
    {
        $telData = new TelegramData();
        $telData->data = json_encode($request->all());
        $telData->path = 'success-crypto';
        $telData->save();
    }


    public function convertDates()
    {

        $panel = Panels::find(1);
        $pasarGuard = new PasarGuard([
            'id' => $panel->id,
            'url' => $panel->url,
            'username' => $panel->username,
            'password' => $panel->password,
        ]);

        $pgUser = $pasarGuard->getAllUsers();

        foreach ($pgUser['users'] as $user){
            $order = Orders::where('uid',$user['id'])->first();
            if (!is_null($order)){
                $order->expire_at = Carbon::parse($user['expire']);
                $order->save();
            }
        }

    }


}
