<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    use ResolvesFileUrls;

    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->getTranslation('name', app()->getLocale()),
            'image'     => $this->fileUrl($this->image),
            'is_special' => (bool) $this->is_special,
        ];
    }
}