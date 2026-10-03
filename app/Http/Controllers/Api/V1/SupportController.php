<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContactMessageRequest;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * "Contact us" screen (public; token optional): the support contact cards and
 * the message form.
 */
class SupportController extends Controller
{
    /**
     * GET /api/v1/support/contact-info
     *
     * The cards at the top of the screen (null when not configured) and, for a
     * signed-in user, the name / email to pre-fill the form with.
     */
    public function contactInfo(Request $request): JsonResponse
    {
        $support = config('checkout.support');
        $user = $request->user('sanctum');
        $digits = fn (?string $phone) => $phone ? preg_replace('/\D+/', '', $phone) : null;

        return ApiResponse::success('messages.success', [
            'email' => $support['email'] ? ['value' => $support['email'], 'url' => 'mailto:'.$support['email']] : null,
            'phone' => $support['phone'] ? ['value' => $support['phone'], 'url' => 'tel:+'.$digits($support['phone'])] : null,
            'whatsapp' => $support['whatsapp'] ? ['value' => $support['whatsapp'], 'url' => 'https://wa.me/'.$digits($support['whatsapp'])] : null,
            'prefill' => $user ? ['name' => $user->name, 'email' => $user->email] : null,
        ]);
    }

    /**
     * POST /api/v1/support/contact  { name, email, message }
     *
     * The message is saved first, then the support inbox is emailed; a mail
     * failure is logged and never loses the message.
     */
    public function store(ContactMessageRequest $request): JsonResponse
    {
        $contact = ContactMessage::query()->create([
            'user_id' => $request->user('sanctum')?->id,
        ] + $request->validated());

        $inbox = config('checkout.support.inbox') ?: config('checkout.support.email');

        if ($inbox) {
            try {
                Mail::to($inbox)->send(new ContactMessageReceived($contact));
            } catch (\Throwable $e) {
                Log::error('Contact message email failed', ['contact_message_id' => $contact->id, 'error' => $e->getMessage()]);
            }
        } else {
            Log::warning('No support inbox configured (SUPPORT_INBOX / SUPPORT_EMAIL): contact message only stored', ['contact_message_id' => $contact->id]);
        }

        return ApiResponse::send(201, 'support.contact_sent', [
            'id' => $contact->id,
            'name' => $contact->name,
            'email' => $contact->email,
            'created_at' => $contact->created_at?->toIso8601String(),
        ]);
    }
}
