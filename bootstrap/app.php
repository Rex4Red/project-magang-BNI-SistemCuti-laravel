<?php

use App\Domain\CutiError;
use App\Http\Middleware\CutiAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [CutiAccess::class]);
        $middleware->trimStrings(except: ['currentPassword', 'newPassword', 'confirmPassword']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (CutiError $error, Request $request) {
            return response()->json(['message' => $error->getMessage(), 'code' => $error->errorCode, 'details' => $error->details], $error->status);
        });
        $exceptions->render(function (ValidationException $error, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Periksa isian: '.implode('; ', $error->validator->errors()->all()), 'code' => 'VALIDATION', 'errors' => $error->errors()], 422);
            }
        });
        $exceptions->render(function (QueryException $error, Request $request) {
            if ($request->is('api/*') && in_array($error->errorInfo[0] ?? '', ['23000', '23505'], true)) {
                return response()->json(['message' => 'Data sudah terdaftar atau masih digunakan.', 'code' => 'CONFLICT'], 409);
            }
        });
    })->create();
