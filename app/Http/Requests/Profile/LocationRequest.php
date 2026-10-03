<?php

namespace App\Http\Requests\Profile;

use App\Exceptions\ProfileLockedException;
use App\Exceptions\ProfileStepException;
use App\Models\CaptainProfile;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $profile = $this->profile();

        if ($profile->status === 'pending') {
            throw ProfileLockedException::forStatus($profile->status);
        }

        $merge = [];

        if ($this->filled('address')) {
            $merge['address'] = trim($this->input('address'));
        }

        if ($this->filled('name')) {
            $merge['name'] = trim($this->input('name'));
        }

        if ($this->filled('phone')) {
            $merge['phone'] = PhoneNumber::normalize($this->input('phone'));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'address'   => ['required', 'string', 'min:3', 'max:500'],
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];

        if ($this->isStore()) {
            $rules['name']  = ['nullable', 'string', 'min:2', 'max:100'];
            $rules['phone'] = ['nullable', 'string', 'regex:'.config('otp.phone_pattern')];
        }

        return $rules;
    }

    public function isStore(): bool
    {
        return $this->user()->isStore();
    }

    public function profile(): StoreProfile|CaptainProfile|ShopperProfile
    {
        return $this->user()?->profile() ?? throw ProfileStepException::basicInfoRequired();
    }

    public function attributes(): array
    {
        return [
            'address'   => __('validation.attributes.address'),
            'latitude'  => __('validation.attributes.latitude'),
            'longitude' => __('validation.attributes.longitude'),
            'name'      => __('validation.attributes.branch_name'),
            'phone'     => __('validation.attributes.phone'),
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => __('auth.invalid_phone'),
        ];
    }
}
