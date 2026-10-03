<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Banner
 */
class BannerResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id'          => $this->id,
            'title'       => $this->getTranslation('title', $locale),
            'subtitle'    => $this->getTranslation('subtitle', $locale) ?: null,
            'button_text' => $this->getTranslation('button_text', $locale) ?: null,
            'image'       => $this->fileUrl($this->image),
            'link'        => $this->link,
            'sort_order'  => $this->sort_order,
        ];
    }
}
