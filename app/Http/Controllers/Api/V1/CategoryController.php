<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryListingRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\Home\LocationResource;
use App\Http\Resources\ProductListingResource;
use App\Http\Resources\CompactStoreListingResource;
use App\Models\Category;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected CatalogService $catalog,
    ) {
    }

    /**
     * GET /api/v1/categories
     */
    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->active()
            ->when($request->filled('search'), fn ($q) => $q->searchName($request->string('search')->trim()->toString()))
            ->orderBy('id')
            ->get();

        return ApiResponse::success('messages.success', CategoryResource::collection($categories));
    }

    /**
     * GET /api/v1/categories/{category}/listings?type=stores|products
     *
     * Stores or products of the category, searchable and filterable.
     *
     * Stores (filed under the category or selling in it) are all listed, ranked
     * favorites first, then by road distance to their nearest branch (when a
     * position is resolved: GPS, cached last GPS, city or saved address), then
     * rating, then delivered orders. Stores without a located branch come last.
     * Only an explicit `within_km` limits the radius. Delivery is priced by
     * DeliveryCalculatorService.
     */
    public function listings(CategoryListingRequest $request, int $category): JsonResponse
    {
        $category = Category::query()->active()->findOrFail($category);
        $filters  = $request->filters($category->id);
        $perPage  = $this->perPage($request);
        $location = null;

        if ($request->isProducts()) {
            $paginator = $this->catalog->products($filters, $perPage);
            $items     = ProductListingResource::collection($paginator);
        } else {
            $location  = $request->location();
            $paginator = $this->catalog->categoryStores($location, $filters, $perPage);
            $items     = CompactStoreListingResource::collection($paginator);   // flat delivery_fee / delivery_time_minutes / distance_in_km
        }

        return ApiResponse::success('messages.success', [
            'category'  => new CategoryResource($category),
            'type'      => $request->type(),
            'counts'    => $this->catalog->categoryCounts($category),
            'location'  => $location ? new LocationResource($location) : null,
            'radius_km' => $location && isset($filters['within_km']) ? (float) $filters['within_km'] : null,
            'filters'   => $this->appliedFilters($filters),
        ] + $this->paginated($paginator, $items));
    }

    /**
     * Echo of the filters that were applied (useful for the filter sheet state).
     *
     * @param  array<string, mixed>  $filters
     */
    protected function appliedFilters(array $filters): object
    {
        return (object) $filters;
    }
}
