<?php

use App\Services\OrderLifecycleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:sync-lifecycle', function () {
    $result = app(OrderLifecycleService::class)->synchronizeAll();
    $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
})->purpose('Synchronize order volume, expiry, suspension and cancellation statuses');

Schedule::command('orders:sync-lifecycle')
    ->everyTenMinutes()
    ->withoutOverlapping(30);
