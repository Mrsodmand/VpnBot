<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HegzaIp;
use App\Models\HegzaMessage;
use App\Models\HegzaUser;
use App\Models\Message;
use App\Models\User;
use App\Services\Telegram;

class JobController extends Controller
{
    public function sendBulkMessage()
    {
        $token = env('TELEGRAM_BOT_TOKEN');

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
                                    'reply_markup' => json_encode([
                                        'inline_keyboard' => [
                                            [
                                                [
                                                    'text' => '📄 جزئیات سفارش',
                                                    'callback_data' => "type=clientSingleOrder|id={$singleOrder['order-id']}",
                                                ]
                                            ]
                                        ]
                                    ]),
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

    }

    public function remindToRenewOrder()
    {

    }


    public function expireOrders()
    {

    }
}
