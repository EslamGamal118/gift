<?php

namespace App\Services\Delivery;

use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Alshrouq Delivery integration API. The token is part of the URL
 * ({base_url}/{token}/orders/create), so URLs are never logged or put in
 * exception messages.
 */
class AlshrouqClient
{
    /**
     * Our status for one of Alshrouq's (its status id or its name, matched
     * case-insensitively; config `alshrouq.statuses`), or null.
     */
    public static function mapStatus(int|string|null $providerStatus): ?string
    {
        $wanted = mb_strtolower(trim((string) $providerStatus));

        if ($wanted === '') {
            return null;
        }

        foreach ((array) config('alshrouq.statuses') as $provider => $status) {
            if (mb_strtolower(trim((string) $provider)) === $wanted) {
                return $status;
            }
        }

        return null;
    }

    /**
     * KSA mobile as Alshrouq expects it: 5XXXXXXXX (no 966 / 0 prefix).
     */
    public static function localPhone(string $phone): string
    {
        $normalized = PhoneNumber::normalize($phone);

        return str_starts_with($normalized, '966') ? substr($normalized, 3) : $normalized;
    }

    public function isConfigured(): bool
    {
        return filled(config('alshrouq.api_token')) && filled(config('alshrouq.base_url'));
    }

    /**
     * Create a delivery order.
     *
     * @param  array<string, mixed>  $payload  see CustomOrderDeliveryService::payload()
     * @return array<string, mixed>  the created order: order_id, status_id, status_label, fees, distance...
     *
     * @throws AlshrouqException
     */
    public function createOrder(array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new AlshrouqException('Alshrouq is not configured (ALSHROUQ_API_TOKEN).');
        }

        $url = rtrim((string) config('alshrouq.base_url'), '/').'/'.rawurlencode((string) config('alshrouq.api_token')).'/orders/create';

        try {
            $response = Http::acceptJson()->asJson()->timeout((int) config('alshrouq.timeout', 20))->post($url, $payload);
        } catch (ConnectionException) {
            throw new AlshrouqException('Alshrouq could not be reached.');
        }

        $body = (array) $response->json();
        // Some integrations wrap the order in `data`
        $order = isset($body['data']['order_id']) ? (array) $body['data'] : $body;

        if ($response->failed() || empty($order['order_id'])) {
            $message = $body['message'] ?? $body['error'] ?? null;
            $errors = isset($body['errors']) && is_array($body['errors']) ? collect($body['errors'])->flatten()->implode(' ') : '';

            throw new AlshrouqException(trim(sprintf('Alshrouq refused the order (HTTP %d): %s %s', $response->status(), is_string($message) ? $message : '', $errors)), $response->status(), $body);
        }

        return $order;
    }
}
