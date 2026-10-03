<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SearchHistory
 */
class SearchHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'keyword'       => $this->keyword,
            'type'          => $this->type,
            'results_count' => $this->results_count,
            'searched_at'   => $this->updated_at?->toIso8601String(),
        ];
    }
}
