<?php

namespace App\Http\Resources;

use App\Models\LegalPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A legal center page in the request's language (Accept-Language). With
 * `?locale=all` the title and sections come in every locale instead:
 * `title: {ar, en}`, `sections: {ar: [...], en: [...]}`.
 *
 * @mixin LegalPage
 */
class LegalPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $all = $request->query('locale') === 'all';

        return [
            'type' => $this->type,
            'locale' => $all ? 'all' : app()->getLocale(),
            'title' => $all ? $this->getTranslations('title') : $this->title,
            'sections' => $all
                ? collect(LegalPage::LOCALES)->mapWithKeys(fn (string $locale) => [$locale => $this->sections($locale)])->all()
                : $this->sections(),
            'last_updated_at' => $this->updated_at?->toIso8601String(),
            'last_updated_label' => $this->updated_at?->copy()->locale(app()->getLocale())->translatedFormat('j F Y'),
        ];
    }
}
