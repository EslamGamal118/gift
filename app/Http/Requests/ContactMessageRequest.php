<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /support/contact { name, email, message }. Open to guests; for a
 * signed-in user (optional Sanctum token) a missing name / email is taken
 * from their account.
 */
class ContactMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user('sanctum');

        $this->merge(array_filter([
            'name' => $this->filled('name') ? trim((string) $this->input('name')) : $user?->name,
            'email' => $this->filled('email') ? trim((string) $this->input('email')) : $user?->email,
            'message' => $this->filled('message') ? trim((string) $this->input('message')) : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('validation.attributes.name'),
            'email' => __('validation.attributes.email'),
            'message' => __('support.message_attribute'),
        ];
    }
}
