<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * مزوّد الرسائل النصية 4Jawaly.
 *
 * يعتمد على إعدادات services.forjawaly (المفتاح، السر، الرابط، اسم المرسل).
 *
 * @see https://4jawaly.com
 */
class ForJawalyProvider
{
    protected ?string $apiUrl;
    protected ?string $appKey;
    protected ?string $appSecret;
    protected ?string $sender;

    public function __construct()
    {
        $this->apiUrl    = config('services.forjawaly.url');
        $this->appKey    = config('services.forjawaly.key');
        $this->appSecret = config('services.forjawaly.secret');
        $this->sender    = config('services.forjawaly.sender');
    }

    /**
     * هل الإعدادات اللازمة للإرسال مكتملة؟
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiUrl) && ! empty($this->appKey) && ! empty($this->appSecret) && ! empty($this->sender);
    }

    /**
     * إرسال رسالة نصية إلى رقم واحد.
     *
     * @param  string  $phone    الرقم بالصيغة الدولية (مثال: 9665XXXXXXXX)
     * @param  string  $message  نص الرسالة
     * @return bool  true عند نجاح الإرسال، false عند الفشل (مع تسجيل السبب في اللوق)
     */
    public function send(string $phone, string $message): bool
    {
        $formattedPhone = preg_replace('/[^0-9]/', '', $phone);

        if (! $this->isConfigured()) {
            Log::critical('[4Jawaly] Credentials or configuration missing', [
                'has_url'    => ! empty($this->apiUrl),
                'has_key'    => ! empty($this->appKey),
                'has_secret' => ! empty($this->appSecret),
                'has_sender' => ! empty($this->sender),
            ]);

            return false;
        }

        try {
            $response = Http::withHeaders([
                    'Authorization' => 'Basic '.base64_encode($this->appKey.':'.$this->appSecret),
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ])
                ->timeout(10)
                ->retry(2, 500, throw: false)
                ->post($this->apiUrl, [
                    'messages' => [
                        [
                            'text'    => $message,
                            'numbers' => [$formattedPhone],
                            'sender'  => $this->sender,
                        ],
                    ],
                ]);

            $result = $response->json();

            if ($response->successful() && in_array((int) ($result['code'] ?? 0), [200, 201], true)) {
                Log::info('[4Jawaly] SMS sent', [
                    'phone'  => $formattedPhone,
                    'job_id' => $result['job_id'] ?? null,
                ]);

                return true;
            }

            Log::error('[4Jawaly] SMS rejected by provider', [
                'phone'    => $formattedPhone,
                'status'   => $response->status(),
                'response' => $result ?? $response->body(),
            ]);

            return false;
        } catch (ConnectionException|RequestException $e) {
            Log::error('[4Jawaly] Connection failed', [
                'phone' => $formattedPhone,
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
