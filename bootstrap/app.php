<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Nginx terminates TLS and proxies to PHP-FPM over http, so without
        // this Laravel builds http:// URLs for an https:// request. Signed
        // routes sign the scheme, so a mismatch fails every signature check.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API failure uses the same {success, message, errors} envelope
        // as every API success, so a client parses one shape.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$status, $message, $errors] = match (true) {
                $e instanceof ValidationException => [
                    422,
                    'The given data was invalid.',
                    $e->errors(),
                ],
                $e instanceof AuthenticationException => [
                    401,
                    'Please sign in to continue.',
                    null,
                ],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [
                    404,
                    'Not found.',
                    null,
                ],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    $e->getMessage() ?: 'Request failed.',
                    null,
                ],
                default => [
                    500,
                    app()->hasDebugModeEnabled()
                        ? $e->getMessage()
                        : 'Something went wrong. Please try again.',
                    null,
                ],
            };

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $errors,
            ], $status);
        });
    })->create();
