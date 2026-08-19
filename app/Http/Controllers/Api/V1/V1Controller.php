<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared v1 API endpoints.
 *
 * Common endpoints used by both the Flutter app and the React dashboard live
 * here. Feature-specific endpoints (chat, location, SOS, ...) get their own
 * controllers in this same App\Http\Controllers\Api\V1 namespace.
 */
class V1Controller extends Controller
{
    /**
     * Connectivity check.
     *
     * Confirms the API is reachable, routing is wired up and the app booted
     * cleanly. Safe to call from a client on startup.
     *
     * GET /api/v1/ping
     */
    public function ping(Request $request): JsonResponse
    {
        return $this->success([
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'api_version' => 'v1',
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'server_time' => now()->toIso8601String(),
        ], 'pong');
    }

    /**
     * Build a successful JSON response.
     *
     * Interim envelope — this will move into a shared trait or base API
     * controller when the API conventions land in Phase 0, step 2.
     */
    protected function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Build a failed JSON response.
     */
    protected function error(string $message = 'Something went wrong', mixed $errors = null, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
