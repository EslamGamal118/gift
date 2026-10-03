<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\LegalPageResource;
use App\Models\LegalPage;
use Illuminate\Http\JsonResponse;

/**
 * Legal center (public, no token): privacy policy and terms and conditions.
 * Localised by Accept-Language; `?locale=all` returns every language.
 */
class LegalPageController extends Controller
{
    /**
     * GET /api/v1/legal
     *
     * The legal center menu: each published page's type, title and last update.
     */
    public function index(): JsonResponse
    {
        $pages = LegalPage::query()->published()->orderBy('id')->get();

        return ApiResponse::success('messages.success', [
            'items' => $pages->map(fn (LegalPage $page) => [
                'type' => $page->type,
                'title' => $page->title,
                'last_updated_at' => $page->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * GET /api/v1/legal/{type}  (privacy_policy | terms_and_conditions)
     */
    public function show(string $type): JsonResponse
    {
        $page = LegalPage::query()->published()->where('type', $type)->firstOrFail();

        return ApiResponse::success('messages.success', new LegalPageResource($page));
    }
}
