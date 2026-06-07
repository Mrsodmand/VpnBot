<?php

namespace App\Http\Controllers;

use App\lib\PasarGuard;
use App\Models\Inbounds;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
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
            'charge_amount' => 'مبالغ شارژ کیف پول',
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
        set_time_limit(9999999);
//        $filePath = base_path('oldData/user.json');
//        $json = file_get_contents($filePath);
//        $users = json_decode($json, true);
//        if (json_last_error() !== JSON_ERROR_NONE) {
//            dd('JSON Error: ' . json_last_error_msg());
//        }
//        foreach ($users as $user) {
//            if (empty($user['user_id'])) {
//                continue;
//            }
//            User::updateOrCreate(
//                [
//                    'tel_id' => $user['user_id'],
//                ],
//                [
//                    'username' => !empty($user['name'])
//                        ? ltrim($user['name'], '@')
//                        : null,
//
//                    'balance' => isset($user['balance'])
//                        ? (float) $user['balance']
//                        : 0,
//
//                    'status' => 1,
//                ]
//            );
//        }

//        $filePath = base_path('oldData/plans.json');
//        $json = file_get_contents($filePath);
//        $plans = json_decode($json, true);
//        if (json_last_error() !== JSON_ERROR_NONE) {
//            dd('JSON Error: ' . json_last_error_msg());
//        }
//
//        foreach ($plans as $plan) {
//            $newPlan = Plans::where('name',$plan['name'])->first();
//            if (is_null($newPlan)){
//                $service = Service::where('name',$plan['plan_type'])->first();
//                $newPlan = new Plans();
//                $newPlan->name = $plan['name'];
//                $newPlan->bandwidth = $plan['volume_gb'];
//                $newPlan->days = $plan['days'];
//                $newPlan->price = $plan['price'];
//                $newPlan->status = 1;
//                $newPlan->type = !is_null($service) ? $service->id : '';
//                $newPlan->save();
//            }
//        }


        $filePath = base_path('oldData/orders.json');

        $json = file_get_contents($filePath);
        $orders = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            dd('JSON Error: ' . json_last_error_msg());
        }

        $panel = Panels::find(1);

        $pasarGuard = new PasarGuard([
            'url' => $panel->url,
            'username' => $panel->username,
            'password' => $panel->password,
            'id' => $panel->id,
        ]);

        if (!$pasarGuard->checkConnection()) {
            return [
                'status' => false,
                'message' => $pasarGuard->getLoginStatus()['message'],
            ];
        }

        foreach ($orders as $order) {
            $checkOrder = Orders::where('remark',$order['email'])->first();
            if (!is_null($checkOrder)){
                continue;
            }

            $result = $pasarGuard->getUser($order['email']);
            if (array_key_exists('username', $result)) {



                $user = User::where('tel_id', $order['vendor_id'])->first();
                $activeGroup = Inbounds::where('inbound_id', $result['group_ids'][0])
                    ->where('panel_id', $panel->id)
                    ->first();
                Orders::updateOrCreate(
                    [
                        'remark' => $order['email'],
                    ],
                    [
                        'user_id' => !is_null($user) ? $user->id : 0,
                        'remark' => $order['email'],
                        'uid' => $result['id'],
                        'sub_id' => $result['subscription_url'],
                        'plan' => 0,
                        'panel_id' => $panel->id,
                        'inbound_id' => $activeGroup->id,
                        'system_type' => 'pasarguard',
                        'expire_at' => Carbon::parse($result['expire'])->format('Y-m-d H:i:s'),
                        'status' => 1,
                        'detail' => [],
                    ]
                );
            }
        }
    }

}
