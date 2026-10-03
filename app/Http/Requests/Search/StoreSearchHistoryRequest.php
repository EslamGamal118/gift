<?php

namespace App\Http\Requests\Search;

use App\Models\SearchHistory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /search/history — save a keyword to the recent searches of the caller.
 */
class StoreSearchHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'keyword' => SearchHistory::normalizeKeyword((string) $this->input('keyword', $this->input('q', ''))),
            'type'    => strtolower((string) $this->input('type', SearchHistory::TYPE_ALL)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'keyword'       => ['required', 'string', 'min:1', 'max:100'],
            'type'          => ['required', Rule::in(SearchHistory::TYPES)],
            'results_count' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'keyword'       => __('validation.attributes.keyword'),
            'type'          => __('validation.attributes.type'),
            'results_count' => __('validation.attributes.results_count'),
        ];
    }
}
