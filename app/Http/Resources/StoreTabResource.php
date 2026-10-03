<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One horizontal filter tab of the store screen (best sellers / all / a category).
 * `key` is the value the client sends back as `tab` to filter the products.
 */
class StoreTabResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key'         => $this->resource['key'],
            'category_id' => $this->resource['category_id'],
            'name'        => $this->resource['name'],
            'image'       => $this->fileUrl($this->resource['image']),
            'count'       => $this->resource['count'],
        ];
    }
}
