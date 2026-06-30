<?php

use App\Models\TelegramData;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        api: __DIR__ . '/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->render(function (Throwable $e, Request $request) {

            if ($request->is('api/telegram-webhook')) {


                $message = $request->all();

                $message['error'] = $e->getMessage();
                $message['line'] = $e->getLine();
                $message['file'] = $e->getFile();

                $telData = new TelegramData();
                $telData->data = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $telData->path = 'error';
                $telData->save();

                return response()->json(['ok' => true], 200);
            }

            return null;
        });

    })
    ->create();
