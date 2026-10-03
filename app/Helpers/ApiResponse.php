<?php

namespace App\Helpers;

class ApiResponse
{
    /**
     * Send a standardized API response with automatic translation
     */
    static function send($code, string $messageKey, $data = null, array $replace = [])
    {
        return response()->json([
            'status'  => $code,
            'message' => __($messageKey, $replace),
            'data'    => $data,
        ], $code, [], JSON_UNESCAPED_UNICODE); 
    }

    /**
     * Shortcut for success response
     */
    static function success(string $messageKey = 'messages.success', $data = null)
    {
        return self::send(200, $messageKey, $data);
    }

    /**
     * Shortcut for error response
     */
    static function error(string $messageKey = 'messages.error', $data = null, $code = 400)
    {
        return self::send($code, $messageKey, $data);
    }
}