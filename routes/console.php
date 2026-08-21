<?php

use App\Services\OrderLifecycleService;
use App\Services\OrderLifecycleNotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:sync-lifecycle', function () {
    $result = [
        'synchronization' => app(OrderLifecycleService::class)->synchronizeAll(),
        'notifications' => app(OrderLifecycleNotificationService::class)->sendDueNotifications(),
    ];
    $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
})->purpose('Synchronize order lifecycle and send due renewal/volume notifications');

Schedule::command('orders:sync-lifecycle')
    ->everyTenMinutes()
    ->withoutOverlapping(30);
