<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait ResolvesFileUrls
{
    /**
     * تحويل مسار الملف المخزّن إلى رابط كامل (يُعاد كما هو إن كان رابطًا أصلًا)
     */
    protected function fileUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
