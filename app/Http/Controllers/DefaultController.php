<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Carbon\Carbon;

class DefaultController extends Controller
{
    public function runDefaultValueForNewProject()
    {

        $settings = [
            'renew' => 'وضعیت تمدید',
            'extra' => 'وضعیت خرید حجم اضافه',
            'sell' => 'وضعیت فروش',
            'referral' => 'وضعیت پورسانت',
            'cart_be_cart' => 'وضعیت کارت به کارت',
            'cart_be_cart_text' => 'متن کارت به کارت',
            'cart_be_cart_random' => 'نمایش تصادفی کارت ها',
            'support_id' => 'آیدی پشتیبانی',
            'report_id' => 'آیدی کانال تراکنش ها',
            'cart_be_cart_id' => 'آیدی کانال کارت به کارت',
        ];

        $dataMap = [];

        foreach ($settings as $key => $label) {

            $row = Setting::firstOrCreate(
                ['key' => $key],
                [
                    'name' => $label,
                    'value' => 0
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


    }


    public function convertUsers()
    {
        set_time_limit(999999999);
        $url = "https://panels3.roopsida.com:6985/kosmikham";
        $data = [
            'url' => $url,
            'username' => 'admin',
            'password' => 'thankyouamie'
        ];

        $session = loginToSanaie($data);
        $data = [
            'id' => 3,
            'url' => $url,
            'session' => $session['session']
        ];

        $getInbound = getInbound($data);
        $userNotFound = [];
        $clients = json_decode($getInbound['inbounds']['settings'], true)['clients'];
        foreach ($clients as $key => $user) {

            if ($key >= 0 && $key <= count($clients)) {
                $data = [
                    'serverUrl' => $url,
                    'sessionCookie' => $session['session'],
                    'uuid' => $user['id'],
                ];
                $obj = getClient($data);
                if (array_key_exists('obj', $obj)) {
                    if (array_key_exists(0, $obj['obj'])) {
                        $obj = $obj['obj'][0];


                        if ($obj['expiryTime'] == 0) {
                            $expireTime = 0;
                        } else {
                            $expireTime = Carbon::now()
                                    ->addDays(85)
                                    ->timestamp * 1000;
                        }


                        if ($expireTime != 0) {
                            $volume = $obj['total'];

                            if ($obj['total'] == 0) {
                                $volume = 0;
                            } elseif ($volume < 0) {
                                $volume = 1 * 1024 * 1024 * 1024;
                            }

                            $updateData = [
                                'serverUrl' => $url,
                                'sessionCookie' => $session['session'],
                                'inboundId' => 3,
                                'uuid' => $obj['uuid'],
                                'email' => $obj['email'],
                                'expiryTimestamp' => $expireTime,
                                'totalGB' => $volume,
                                "enable" => true,
                            ];
                        }

                    } else {
                        $userNotFound[] = [
                            'uid' => $user['id'],
                            'email' => $user['email']
                        ];
                    }
                } else {
                    $userNotFound[] = [
                        'uid' => $user['id'],
                        'email' => $user['email']
                    ];
                }
            }
        }
        dd($updateData);
        $data = [
            'clients' => $newUser,
            'url' => $url,
            'inbound_id' => 3,
            'session' => $session['session']
        ];
//        dd(createBulkUser($data), $userNotFound);


    }

}
