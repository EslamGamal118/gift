<?php

namespace App\Http\Requests\Profile\Concerns;

use App\Models\StoreProfile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for the store's weekly schedule:
 *   working_hours[saturday][is_open]=1&working_hours[saturday][from]=09:00&working_hours[saturday][to]=23:00 ...
 * or the same structure as a JSON string (convenient for multipart requests).
 */
trait ValidatesWorkingHours
{
    /**
     * Decode a JSON-encoded working_hours payload so it can be validated as an array.
     */
    protected function decodeWorkingHours(): array
    {
        if (! is_string($this->input('working_hours'))) {
            return [];
        }

        $decoded = json_decode($this->input('working_hours'), true);

        return ['working_hours' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @param  string  $presence  "required" for the store-details step, "sometimes" for partial updates
     * @return array<string, mixed>
     */
    protected function workingHoursRules(string $presence = 'required'): array
    {
        $rules = [
            'working_hours' => [$presence, 'array', 'required_array_keys:'.implode(',', StoreProfile::DAYS)],
        ];

        foreach (StoreProfile::DAYS as $day) {
            $isOpen = fn () => $this->dayIsOpen($day);

            $rules["working_hours.{$day}"]         = ['required', 'array'];
            $rules["working_hours.{$day}.is_open"] = ['required', 'boolean'];
            $rules["working_hours.{$day}.from"]    = [Rule::requiredIf($isOpen), 'nullable', 'date_format:H:i'];
            $rules["working_hours.{$day}.to"]      = [Rule::requiredIf($isOpen), 'nullable', 'date_format:H:i'];
        }

        // Nested day rules only apply when the schedule itself is present.
        if ($presence === 'sometimes') {
            foreach ($rules as $key => $rule) {
                if ($key !== 'working_hours') {
                    array_unshift($rules[$key], 'sometimes');
                }
            }
        }

        return $rules;
    }

    /**
     * Cross-field checks: unknown days and identical open/close times.
     * Closing after midnight (e.g. 16:00 -> 02:00) is allowed.
     */
    protected function validateWorkingHours(Validator $validator): void
    {
        if (! $this->has('working_hours')) {
            return;
        }

        $hours = (array) $this->input('working_hours', []);

        foreach (array_diff(array_keys($hours), StoreProfile::DAYS) as $unknown) {
            $validator->errors()->add("working_hours.{$unknown}", __('profile.working_hours_unknown_day', ['day' => $unknown]));
        }

        foreach (StoreProfile::DAYS as $day) {
            if (! $this->dayIsOpen($day)) {
                continue;
            }

            $from = data_get($hours, "{$day}.from");
            $to   = data_get($hours, "{$day}.to");

            if ($from && $to && $from === $to) {
                $validator->errors()->add("working_hours.{$day}.to", __('profile.working_hours_same_time'));
            }
        }
    }

    protected function dayIsOpen(string $day): bool
    {
        return filter_var(data_get($this->input('working_hours'), "{$day}.is_open"), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Storage-ready schedule: every day present, closed days without times.
     *
     * @return array<string, array{is_open: bool, from: ?string, to: ?string}>
     */
    public function normalizedWorkingHours(): array
    {
        $hours = (array) $this->validated('working_hours', []);
        $out   = [];

        foreach (StoreProfile::DAYS as $day) {
            $isOpen = $this->dayIsOpen($day);

            $out[$day] = [
                'is_open' => $isOpen,
                'from'    => $isOpen ? data_get($hours, "{$day}.from") : null,
                'to'      => $isOpen ? data_get($hours, "{$day}.to") : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    protected function workingHoursAttributes(): array
    {
        $attributes = ['working_hours' => __('validation.attributes.working_hours')];

        foreach (StoreProfile::DAYS as $day) {
            $dayName = __('profile.days.'.$day);

            $attributes["working_hours.{$day}"]         = $dayName;
            $attributes["working_hours.{$day}.is_open"] = __('validation.attributes.working_hours_is_open', ['day' => $dayName]);
            $attributes["working_hours.{$day}.from"]    = __('validation.attributes.working_hours_from', ['day' => $dayName]);
            $attributes["working_hours.{$day}.to"]      = __('validation.attributes.working_hours_to', ['day' => $dayName]);
        }

        return $attributes;
    }
}
