<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The single place the API response envelope is defined.
 *
 * Every endpoint returns the same shape, so clients can parse one structure:
 *
 *   { "success": bool, "message": string, "data": mixed, "errors": mixed }
 */
trait ApiResponse
{
    protected function ok(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function fail(
        string $message = 'Something went wrong',
        mixed $errors = null,
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
