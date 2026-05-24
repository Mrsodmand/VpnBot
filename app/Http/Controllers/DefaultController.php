<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

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

}
