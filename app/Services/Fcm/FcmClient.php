<?php

namespace App\Services\Fcm;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal Firebase Cloud Messaging (HTTP v1) client built on Laravel's HTTP
 * client: mints an OAuth2 access token from the service-account JSON and
 * posts one message per device token (v1 has no multicast; requests are
 * pooled). Reports tokens FCM says are dead so they can be pruned.
 */
class FcmClient
{
    protected const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    protected const POOL_SIZE = 50;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $credentials = null;

    public function __construct()
    {
        $path = (string) config('services.fcm.credentials', '');

        // Relative paths are resolved from the project root.
        if ($path !== '' && ! str_starts_with($path, '/') && ! preg_match('/^[a-zA-Z]:/', $path)) {
            $path = base_path($path);
        }

        if ($path !== '' && is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->credentials = is_array($decoded) ? $decoded : null;
        }
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.fcm.enabled', true)
            && $this->credentials !== null
            && isset($this->credentials['client_email'], $this->credentials['private_key'], $this->credentials['token_uri'])
            && $this->projectId() !== '';
    }

    public function projectId(): string
    {
        return (string) (config('services.fcm.project_id') ?: ($this->credentials['project_id'] ?? ''));
    }

    /**
     * Send the same notification to many device tokens.
     *
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data  Custom key/value payload (strings only)
     * @return array{sent: int, failed: int, invalid: list<string>}
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        $result = ['sent' => 0, 'failed' => 0, 'invalid' => []];

        if ($tokens === [] || ! $this->isConfigured()) {
            return $result;
        }

        $accessToken = $this->accessToken();
        $endpoint = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $this->projectId());

        foreach (array_chunk($tokens, self::POOL_SIZE) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $endpoint, $accessToken, $title, $body, $data) {
                foreach ($chunk as $token) {
                    $pool->as($token)
                        ->withToken($accessToken)
                        ->acceptJson()
                        ->timeout(15)
                        ->post($endpoint, ['message' => $this->message($token, $title, $body, $data)]);
                }
            });

            foreach ($responses as $token => $response) {
                if (! $response instanceof Response) {
                    // Connection-level failure: keep the token, it may be transient.
                    $result['failed']++;
                    Log::warning('fcm.request_failed', ['error' => (string) $response]);

                    continue;
                }

                if ($response->successful()) {
                    $result['sent']++;

                    continue;
                }

                $result['failed']++;

                if ($this->isDeadToken($response)) {
                    $result['invalid'][] = (string) $token;
                } else {
                    Log::warning('fcm.send_failed', ['status' => $response->status(), 'body' => $response->json() ?? $response->body()]);
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    protected function message(string $token, string $title, string $body, array $data): array
    {
        return [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'high', 'notification' => ['sound' => 'default']],
            'apns' => [
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => ['sound' => 'default', 'content-available' => 1]],
            ],
        ];
    }

    /**
     * UNREGISTERED / NOT_FOUND / invalid-argument-on-token mean the device token is gone for good.
     */
    protected function isDeadToken(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        $json = $response->json() ?? [];
        $status = data_get($json, 'error.status');

        foreach (data_get($json, 'error.details', []) as $detail) {
            if (in_array($detail['errorCode'] ?? null, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                return true;
            }
        }

        return $status === 'NOT_FOUND';
    }

    /**
     * OAuth2 access token via the service account (JWT bearer grant), cached until shortly before expiry.
     */
    protected function accessToken(): string
    {
        $cacheKey = 'fcm:access_token:'.md5((string) $this->credentials['client_email']);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $claims = [
            'iss' => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $this->credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode($claims)),
        ];

        $signature = '';
        $key = openssl_pkey_get_private((string) $this->credentials['private_key']);
        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM: unable to sign the service-account JWT.');
        }
        $segments[] = $this->base64Url($signature);

        $response = Http::asForm()->timeout(15)->post((string) $this->credentials['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => implode('.', $segments),
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('FCM: token exchange failed: '.$response->body());
        }

        $token = (string) $response->json('access_token');
        $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);

        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
