<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\DefaultController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\WpSyncController;

Route::get('runDefaultValueForNewProject',[DefaultController::class,'runDefaultValueForNewProject']);
Route::post('telegram-webhook',[TelegramBotController::class,'index']);
Route::get('setWebhook',[DefaultController::class,'setWebhook']);
Route::get('deleteWebhook',[DefaultController::class,'deleteWebhook']);
Route::get('export',[DefaultController::class,'exportData']);
Route::get('send-bulk-message',[JobController::class,'sendBulkMessage']);

Route::get('importUsers',[DefaultController::class,'importUsers']);

Route::prefix('wp-sync')->group(function () {
    Route::get('ping', [WpSyncController::class, 'ping']);
    Route::post('wallet/balance', [WpSyncController::class, 'walletBalance']);
    Route::post('wallet/credit', [WpSyncController::class, 'walletCredit']);
    Route::post('wallet/debit', [WpSyncController::class, 'walletDebit']);
    Route::post('orders/list', [WpSyncController::class, 'ordersList']);
    Route::post('orders/import-site', [WpSyncController::class, 'importSiteOrder']);
    Route::post('link/disconnect', [WpSyncController::class, 'linkDisconnect']);
    Route::post('admin/stats', [WpSyncController::class, 'adminStats']);
    Route::post('admin/list', [WpSyncController::class, 'adminList']);
});

Route::prefix('ramzino')->group(function (){
   Route::get('fail-callback',[JobController::class,'ramzinoFailCallback']);
});
