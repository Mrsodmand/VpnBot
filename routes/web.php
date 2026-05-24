<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\Controller;

Route::get('/', function () {
    return view('welcome');
});

Route::get('runDefaultValueForNewProject',[Controller::class,'runDefaultValueForNewProject']);
