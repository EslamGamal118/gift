<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\FaqResource;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FAQ screen (public, no token), in the Accept-Language language.
 */
class FaqController extends Controller
{
    /**
     * GET /api/v1/faqs?category=orders&search=
     *
     * Active questions in screen order, plus the categories that have any
     * (for the filter chips).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(Faq::CATEGORIES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $faqs = Faq::query()
            ->active()
            ->when($filters['category'] ?? null, fn ($q, string $category) => $q->where('category', $category))
            ->matching($filters['search'] ?? null)
            ->ordered()
            ->get();

        $categories = Faq::query()->active()->whereNotNull('category')->distinct()->pluck('category');

        return ApiResponse::success('messages.success', [
            'filters' => ['category' => $filters['category'] ?? null, 'search' => $filters['search'] ?? null],
            'categories' => collect(Faq::CATEGORIES)
                ->filter(fn (string $key) => $categories->contains($key))
                ->map(fn (string $key) => ['key' => $key, 'label' => __('faqs.categories.'.$key)])
                ->values(),
            'items' => FaqResource::collection($faqs),
        ]);
    }
}
