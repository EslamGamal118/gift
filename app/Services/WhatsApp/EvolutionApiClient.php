<?php

namespace App\Services\WhatsApp;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Minimal WhatsApp sender over Evolution API (v2):
 * POST {url}/message/sendText/{instance}  { number, text }  with the `apikey` header.
 */
class EvolutionApiClient
{
    public function isConfigured(): bool
    {
        return (bool) config('services.evolution.enabled', true)
            && filled(config('services.evolution.url'))
            && filled(config('services.evolution.key'))
            && filled(config('services.evolution.instance'));
    }

    /**
     * Send a plain text message. `$phone` in any local / international form;
     * it is normalized to digits with the country code (9665XXXXXXXX).
     *
     * @throws RuntimeException when the API is unreachable or rejects the message
     */
    public function sendText(string $phone, string $text): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Evolution API is not configured.');
        }

        $number = PhoneNumber::normalize($phone);
        $url = rtrim((string) config('services.evolution.url'), '/')
            .'/message/sendText/'.rawurlencode((string) config('services.evolution.instance'));

        try {
            $response = Http::withHeaders(['apikey' => (string) config('services.evolution.key')])
                ->acceptJson()
                ->timeout((int) config('services.evolution.timeout', 15))
                ->post($url, ['number' => $number, 'text' => $text]);
        } catch (Throwable $e) {
            throw new RuntimeException('Evolution API unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            Log::warning('Evolution API rejected a WhatsApp message', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw new RuntimeException("Evolution API error {$response->status()}");
        }
    }
}
