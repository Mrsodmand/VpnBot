<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\DefaultController;
use App\Http\Controllers\Api\JobController;

Route::get('runDefaultValueForNewProject',[DefaultController::class,'runDefaultValueForNewProject']);
Route::post('telegram-webhook',[TelegramBotController::class,'index']);
Route::get('export',[DefaultController::class,'exportData']);
Route::get('send-bulk-message',[JobController::class,'sendBulkMessage']);
