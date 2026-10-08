<?php

use App\Exceptions\RateNotFoundException;
use App\Exceptions\RatesProviderInvalidResponseException;
use App\Exceptions\RatesProviderUnavailableException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('rate') || $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (
            LockTimeoutException $timeoutException,
            Request $request
        ) {
            if (!$request->is('rate')) {
                return null;
            }

            return response()->json([
                'message' => 'Rate loading is currently busy. Please try again later.'
            ], 503);
        });

        $exceptions->render(function (
            LimiterTimeoutException $timeoutException,
            Request $request
        ) {
            if (!$request->is('rate')) {
                return null;
            }

            return response()->json([
                'message' => 'Rate loading is currently busy. Please try again later.'
            ], 503);
        });

        $exceptions->render(function (
            RateNotFoundException $e,
            Request $request,
        ) {
            if (!$request->is('rate')) {
                return null;
            }

            return response()->json([
                'message' => 'Currency rate is unavailable for the requested date.',
            ], 404);
        });

        $exceptions->render(function (
            RatesProviderUnavailableException $e,
            Request $request,
        ) {
            if (!$request->is('rate')) {
                return null;
            }

            return response()->json([
                'message' => 'CBR is temporarily unavailable. Please retry later.',
            ], 503);
        });

        $exceptions->render(function (
            RatesProviderInvalidResponseException $e,
            Request $request,
        ) {
            if (!$request->is('rate')) {
                return null;
            }

            return response()->json([
                'message' => 'Failed to obtain valid currency rates from CBR.',
            ], 502);
        });
    })->create();
