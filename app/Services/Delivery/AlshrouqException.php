<?php

namespace App\Services\Delivery;

use RuntimeException;

class AlshrouqException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $response  Alshrouq's response body, if any
     */
    public function __construct(string $message, int $status = 0, public readonly array $response = [])
    {
        parent::__construct($message, $status);
    }
}
