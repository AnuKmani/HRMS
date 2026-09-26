<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The single place the API response envelope is built.
 *
 * Every API response — controller, exception handler, rate limiter — goes
 * through here so clients can rely on exactly two shapes:
 *
 *   success: { "success": true,  "message": "...", "data":    {...} }
 *   failure: { "success": false, "message": "...", "errors":  {...} }
 *
 * `data` and `errors` are always JSON objects (never `[]`), so Flutter can
 * treat them as maps without null/type checks.
 */
class ApiResponse
{
    /**
     * @param  array<string, string>|null  $errors  field => message map
     * @param  array<string, string>  $headers  extra response headers (e.g. Retry-After)
     */
    public static function success(string $message, mixed $data = null, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data ?? (object) [],
        ], $status, $headers);
    }

    /**
     * 201 Created — same envelope, conventional status for a new resource.
     *
     * @param  array<string, string>  $headers
     */
    public static function created(string $message, mixed $data = null, array $headers = []): JsonResponse
    {
        return self::success($message, $data, 201, $headers);
    }

    /**
     * Failure envelope. `$errors` is omitted from the body only when there is
     * nothing field-specific to report, but the key is always present.
     *
     * @param  array<string, string>|object|null  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(string $message, array|object|null $errors = null, int $status = 400, array $headers = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors ?? (object) [],
        ], $status, $headers);
    }
}
