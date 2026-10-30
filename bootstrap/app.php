<?php

use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => EnsureActiveUser::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation']);
        $exceptions->report(function (QueryException $exception) {
            Log::error('Error de persistencia SICOVI', ['sqlstate' => $exception->getCode()]);
        })->stop();
        $exceptions->render(function (QueryException $exception, Request $request) {
            if (in_array($exception->getCode(), ['23505', '23503', '23514'], true)) {
                $message = 'No se pudo guardar: hay un valor duplicado, una relación incompatible o un registro que todavía está en uso.';
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 422);
                }

                return back()->withInput($request->except(['password', 'password_confirmation', 'current_password']))->withErrors(['record' => $message]);
            }
        });
    })->create();
