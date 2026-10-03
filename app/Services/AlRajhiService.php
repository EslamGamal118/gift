<?php

namespace App\Services;

use App\Interfaces\Payable;
use App\Interfaces\PaymentGatewayInterface;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\PaymentTransaction;
use App\Services\Payments\PayableResolver;
use App\Services\PaymentService;
class AlRajhiService extends BasePaymentService implements PaymentGatewayInterface
{

    protected AlRajhiEncryptionService $encryptionService;

    protected $id;
    protected $password;
    protected PaymentService $paymentService;
    public function __construct(PaymentService $paymentService)
    {
        $this->encryptionService = new AlRajhiEncryptionService();
        $this->paymentService = $paymentService;
        $this->base_url = env('ALRAJHI_BASE_URL');
        $this->id = env('ALRAJHI_TRANSPORTAL_ID');
        $this->password = env('ALRAJHI_PASSWORD');
        $this->header = [
            'Content-Type' => 'application/json',
            'accept' => 'application/json',
        ];
    }

    //✅✅
    public function sendPayment(Payable $order): array
    {
      
        $plainData = [
            [
                "id" => $this->id,
                "password" => $this->password,
                "action" => "1",
                "currencyCode" => "682",
                "errorURL" => route('payment.failed'),
                "responseURL" => route('payment.callback'),
                "trackId" => $order->paymentKey() . '_' . time(),   // "15_..." store order, "CO-15_..." custom order
                "amt" => $order->total_amount,
            ]
        ];
        // Step 2: Encrypt Plain Data
        $encryptedData = $this->encryptionService->encrypt(json_encode($plainData));

        $encryptedRequest = [
            [
                "id" => $this->id,
                "trandata" => $encryptedData,
                "errorURL" => route('payment.failed'),
                "responseURL" => route('payment.callback'),
            ]
        ];


        $response = $this->buildRequest('POST', '/pg/payment/hosted.htm', $encryptedRequest);

        Storage::put('response.json', json_encode($response));

        $response_data = $response->getData(true);

        // buildRequest() returns {success:false, message:...} with no 'data' key
        // when the HTTP call itself fails (timeout, DNS, TLS), so check first.
        if (empty($response_data['success'])) {
            Log::error('AlRajhi: payment initiation request failed', [
                'order' => $order->paymentKey(),
                'status' => $response_data['status'] ?? null,
                'message' => $response_data['message'] ?? null,
                'data' => $response_data['data'] ?? null,
            ]);

            return $this->failed();
        }

        // Success body is [{"status":"1","result":"<paymentId>:<url>"}];
        // a gateway-side rejection is [{"status":"2","error":"...","errorText":"..."}].
        $result = $response_data['data'][0]['result'] ?? null;

        if (! is_string($result) || ! str_contains($result, ':')) {
            Log::error('AlRajhi: unexpected payment initiation response', [
                'order' => $order->paymentKey(),
                'data' => $response_data['data'] ?? null,
            ]);

            return $this->failed();
        }

        [$paymentID, $url] = explode(':', $result, 2);

        return [
            'success' => true,
            'url' => $url . '?PaymentID=' . $paymentID,
        ];
    }

    /**
     * Refund a captured payment in full: tranportal action "2" with the
     * original transId / trackId, encrypted like the payment request
     * (POST /pg/payment/tranportal.htm). The ids come from the captured
     * callback stored in payment_transactions.
     *
     * @return array{success: bool, reference: ?string, payload: array<string, mixed>, message?: string}
     */
    public function refund(Payable $order): array
    {
        $captured = $order->transactions()
            ->where('status', PaymentTransaction::STATUS_CAPTURED)
            ->latest('id')
            ->get()
            ->first(fn (PaymentTransaction $t) => filled($t->payload['transId'] ?? null));

        $transId = (string) ($captured?->payload['transId'] ?? '');
        $trackId = (string) ($captured?->payload['trackId'] ?? '');

        if ($transId === '' || $trackId === '') {
            return ['success' => false, 'reference' => null, 'payload' => [], 'message' => 'no AlRajhi transId for the payment'];
        }

        $plainData = [[
            'id' => $this->id,
            'password' => $this->password,
            'action' => '2',
            'currencyCode' => '682',
            'errorURL' => route('payment.failed'),
            'responseURL' => route('payment.callback'),
            'trackId' => $trackId,
            'transId' => $transId,
            'amt' => $order->total_amount,
        ]];

        $response = $this->buildRequest('POST', '/pg/payment/tranportal.htm', [[
            'id' => $this->id,
            'trandata' => $this->encryptionService->encrypt(json_encode($plainData)),
            'errorURL' => route('payment.failed'),
            'responseURL' => route('payment.callback'),
        ]])->getData(true);

        $body = $response['data'][0] ?? [];

        // The answer may come encrypted (trandata) like the payment callback, or in clear
        if (is_array($body) && isset($body['trandata'])) {
            $body = json_decode(urldecode((string) $this->encryptionService->decrypt($body['trandata'])), true)[0] ?? [];
        }

        $result = is_array($body) ? strtoupper((string) ($body['result'] ?? '')) : '';
        $success = ! empty($response['success']) && empty($body['errorText']) && in_array($result, ['CAPTURED', 'SUCCESS'], true);

        if (! $success) {
            Log::error('AlRajhi: refund failed', ['order' => $order->paymentKey(), 'response' => $response]);
        }

        return [
            'success' => $success,
            'reference' => (string) ($body['transId'] ?? $transId),
            'payload' => is_array($body) ? $body : [],
            'message' => $success ? null : (string) ($body['errorText'] ?? $body['error'] ?? $response['message'] ?? 'refund not confirmed'),
        ];
    }

    private function failed(): array
    {
        return [
            'success' => false,
            'url' => route('payment.failed'),
        ];
    }

    //✅✅
   public function callBack(Request $request): ?Payable
    {
        $tran_data = $this->encryptionService->decrypt($request->get('trandata'));
        $data = json_decode(urldecode($tran_data), true);
        $paymentDetails = $data[0] ?? [];

        if (isset($paymentDetails['result']) && $paymentDetails['result'] === 'CAPTURED') {
            $merchantTrackId = (string) ($paymentDetails['trackId'] ?? '');
            $order = app(PayableResolver::class)->find(explode('_', $merchantTrackId)[0]);

            if ($order && ! $order->isPaid()) {
                $success = $this->paymentService->completeOrderPayment($order, $paymentDetails);

                // completeOrderPayment() saved a fresh copy; return that state, not the stale one
                return $success ? $order->fresh() : null;
            }

            return $order;
        }

        return null;
    }
}