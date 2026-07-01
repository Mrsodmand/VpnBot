<?php

namespace App\Http\Controllers;

use App\Lib\PasarGuard;
use App\Models\ConvertedGb;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Telegram;
use Illuminate\Support\Facades\DB;

class DefaultController extends Controller
{
    public function runDefaultValueForNewProject()
    {

        $settings = [
            'renew' => [
                'name' => 'وضعیت تمدید',
                'value' => 1
            ],
            'extra' => [
                'name' => 'وضعیت خرید حجم اضافه',
                'value' => -1
            ],
            'sell' => [
                'name' => 'وضعیت فروش',
                'value' => 1
            ],
            'referral' => [
                'name' => 'وضعیت پورسانت',
                'value' => 0
            ],
            'cart_be_cart' => [
                'name' => 'وضعیت کارت به کارت',
                'value' => 1
            ],
            'cart_be_cart_random' => [
                'name' => 'نمایش تصادفی کارت ها',
                'value' => -1
            ],
            'support_id' => [
                'name' => 'آیدی پشتیبانی',
                'value' => '@supportId'
            ],
            'report_id' => [
                'name' => 'آیدی کانال تراکنش ها',
                'value' => '@transactionId'
            ],
            'cart_be_cart_id' => [
                'name' => 'آیدی کانال کارت به کارت',
                'value' => '@cartBeCartId'
            ],
            'channel_id' => [
                'name' => 'آیدی کانال',
                'value' => '@channelId'
            ],
            'site_address' => [
                'name' => 'آدرس سایت',
                'value' => 'https://expample.com'
            ],
            'charge_amount' => [
                'name' => 'مبالغ شارژ کیف پول',
                'value' => 0
            ],
            'home-page' => [
                'name' => 'متن منو',
                'value' => null
            ],
            'join-bot' => [
                'name' => 'ثبت نام',
                'value' => 1
            ],
            'join-with-referral' => [
                'name' => 'عضویت با رفرال',
                'value' => -1
            ],
            'channel-join' => [
                'name' => 'جوین اجباری',
                'value' => -1
            ]
        ];

        $dataMap = [];

        foreach ($settings as $key => $label) {

            $row = Setting::firstOrCreate(
                ['key' => $key],
                [
                    'name' => $label['name'],
                    'value' => $label['value']
                ]
            );

            $dataMap[$key] = $row;
        }

        Setting::firstOrCreate(
            ['key' => 'commission'],
            [
                'name' => 'درصد پورسانت',
                'value' => 0
            ]
        );
        Setting::firstOrCreate(
            ['key' => 'commission_text'],
            [
                'name' => 'متن پورسانت',
                'value' => 'درصد پورسانت به صورت پیش‌فرض اعمال می‌شود.'
            ]
        );
        $vipService = Service::where('name', 'vip')->first();
        if (is_null($vipService)) {
            $vipService = new Service();
            $vipService->name = 'vip';
            $vipService->status = 1;
            $vipService->price_per_gb = 5000;
            $vipService->save();
        }
        $vipService = Service::where('name', 'normal')->first();
        if (is_null($vipService)) {
            $vipService = new Service();
            $vipService->name = 'normal';
            $vipService->status = 1;
            $vipService->price_per_gb = 5000;
            $vipService->save();
        }
    }

    public function exportData()
    {
        $ids = [
            4437,
            4433,
            4362,
            4340,
            4404,
            4382,
            4381,
            4326,
            4321,
            4288,
            4348,
            4344,
            4426,
            4385,
            4329,
            3290,
            3231,
            4349,
            4347,
            4365,
            4324,
            4446,
            4358,
            4436,
            4435,
            4393,
            4397,
            4371,
            4370,
            4430,
            4355,
            4429,
            4335,
            4301,
            4354,
            4320,
            4438,
            4334,
            4458,
            4312,
            4405,
            4327,
            4372,
            4434,
            4413,
            4412,
            4396,
            3259,
            4387,
            4342,
            4307,
            4350,
            4325,
            4423,
            4380,
            4357,
            4302,
            4364,
            4454,
            4373,
            4425,
            4411,
            4368,
            3244,
            4346,
            4343,
            4379,
            4388,
            4363,
            4378,
            4356,
            4391,
            4322,
            4377,
            3341,
            4366,
            4352,
            4336,
            4332,
            4394,
            4310,
            4406,
            4338,
            4333,
            4369,
            4389,
            4331,
            4323,
            4328,
            4383,
            4444,
            4330,
            4447,
            4375,
            4402,
            4399,
            4442,
            4386,
            4400,
            4345,
            4455,
            4359,
            4398,
            4415,
            4395,
            4416,
            4351,
        ];
        $orders = Orders::wherein('uid', $ids)->get();

        $panel = Panels::find(1);
        $pasarGuard = new PasarGuard([
            'url' => $panel->url,
            'username' => $panel->username,
            'password' => $panel->password,
            'id' => $panel->id,
        ]);

        foreach ($orders as $order) {

            $data = [
                'status' => 'active',
                'group_ids' => [13]
            ];
            $result = $pasarGuard->updateUserById($order->uid, $data);
            $order->inbound_id = 13;
            $order->save();
        }

    }

    public function setWebhook()
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $telegram = new Telegram($token);
        return $telegram->setWebhook([
            'url' => "https://pseudoperipteral-latesha-unpathetically.ngrok-free.dev/api/telegram-webhook",
            'drop_pending_updates' => true
        ]);
    }

    public function deleteWebhook()
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $telegram = new Telegram($token);
        return $telegram->deleteWebhook([
            'drop_pending_updates' => true
        ]);
    }

//    public function importUsers()
//    {
//        set_time_limit(999999999);
//        $username = 'admin';
//        $password = 'admin';
//        $path = ':6969/XdHAetp';
//        $serverName = 'de-4';
//        $inboundId = 2;
//        $url = 'http://38.180.81.220';
//
//        $data = [
//            'username' => $username,
//            'password' => $password,
//            'url' => $url . $path,
//        ];
//
//        $session = loginToSanaie($data)['session'];
//
//        $data['session'] = $session;
//        $data['id'] = $inboundId;
//        $inbounds = getInbound($data);
//
//        $clients = json_decode($inbounds['inbounds']['settings'], true);
//        $carbon = Carbon::parse('2026-02-28', 'UTC');
//        $warTime = $carbon->timestamp * 1000;
//
//        $oldClients = [];
//
//        foreach ($clients['clients'] as $item) {
//            if (!empty($item['expiryTime']) && $item['expiryTime'] > $warTime) {
//                $oldClients[] = [
//                    'uid' => $item['id'],
//                ];
//            }
//        }
//
//        foreach ($oldClients as $old) {
//
//            try {
//                $modal = ConvertedGb::where('uid', $old['uid'])->first();
//                if (is_null($modal)) {
//                    $oldData = [
//                        'serverUrl' => $url . $path,
//                        'sessionCookie' => $session,
//                        'uuid' => $old['uid']
//                    ];
//                    $used = getClient($oldData);
//
//                    if ($used['success']) {
//
//                        $ip = DB::table('ip')
//                            ->where('u_id', $old['uid'])
//                            ->first();
//                        $user = DB::table('customer')
//                            ->where('id', $ip->customer_id)
//                            ->first();
//
//                        $usedData = $used['obj'][0];
//                        $total = byteToGb($usedData['total'] - $usedData['allTime']);
//
//                        if ($total > 0) {
//                            $modal = new ConvertedGb();
//                            $modal->uid = $old['uid'];
//                            $modal->tel_id = !is_null($user->tel_id) ? $user->tel_id : "";
//                            $modal->mobile = !is_null($user->mobile) ? $user->mobile : "";
//                            $modal->order_id = $ip->order_id;
//                            $modal->server = $serverName;
//                            $modal->gb = $total;
//                            $modal->save();
//                        }
//                    }
//                }
//            } catch (\Exception $exception) {
//                dd($user, $modal, $ip, $exception->getMessage(), $used);
//            }
//        }
//        $totalGb = ConvertedGb::where('server', $serverName)->sum('gb');
//        dd($totalGb);
//    }

    public function importUsers()
    {
        $backups = [
            'ch1', 'de2', 'de3', 'de4', 'de5', 'de6', 'fi1', 'fr1', 'it1', 'nl1', 'pl1', 'uk2', 'us1', 'tr1', 'ae2'
        ];
        set_time_limit(999999999);
        foreach ($backups as $serverName) {
            $filePath = base_path("oldData/$serverName.json");
            $json = file_get_contents($filePath);
            $oldClients = json_decode($json, true);
            foreach ($oldClients as $old) {
                try {
                    $modal = ConvertedGb::where('uid', $old['email'])->first();
                    if (is_null($modal)) {

                        $ip = DB::table('ip')
                            ->where('remark', $old['email'])
                            ->first();

                        if (!is_null($ip)) {
                            $user = DB::table('customer')
                                ->where('id', $ip->customer_id)
                                ->first();

                            $usedData = $old['down'] + $old['up'];
                            $total = byteToGb($old['total'] - $usedData);

                            if ($total > 0) {
                                $modal = new ConvertedGb();
                                $modal->uid = $old['email'];
                                $modal->tel_id = !is_null($user->tel_id) ? $user->tel_id : "";
                                $modal->mobile = !is_null($user->mobile) ? $user->mobile : "";
                                $modal->order_id = $ip->order_id;
                                $modal->server = $serverName;
                                $modal->gb = $total;
                                $modal->save();
                            }
                        }

                    }
                } catch (\Exception $exception) {
                    dd($old, $user, $ip, $exception->getMessage());
                }
            }
            $totalGb[] = [
                'Server' => $serverName,
                'Bw' => ConvertedGb::where('server', $serverName)->sum('gb') . ' GB',
                'price' => number_format(5000 * ConvertedGb::where('server', $serverName)->sum('gb')),
            ];

        }

        dd($totalGb);

    }


}
