<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductListingResource;
use App\Http\Resources\StoreListingResource;
use App\Models\SearchHistory;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

/**
 * Global search across stores, products and categories.
 * Public endpoint; when a valid token is sent the keyword is added to the recent searches.
 */
class SearchController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected CatalogService $catalog,
    ) {
    }

    /**
     * GET /api/v1/search?q=...&type=all|stores|products|categories
     */
    public function index(SearchRequest $request): JsonResponse
    {
        $keyword = $request->keyword();
        $type    = $request->type();

        $data = match ($type) {
            SearchHistory::TYPE_STORES     => $this->stores($request),
            SearchHistory::TYPE_PRODUCTS   => $this->products($request),
            SearchHistory::TYPE_CATEGORIES => $this->categories($request),
            default                        => $this->all($request),
        };

        $this->remember($request->user('sanctum'), $keyword, $type, $data['total'], $request->shouldSave());

        return ApiResponse::success('messages.success', ['keyword' => $keyword, 'type' => $type] + $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function all(SearchRequest $request): array
    {
        $limit = (int) config('stores.search.group_limit', 5);

        $categories = $this->catalog->categoriesQuery($request->keyword())->limit($limit)->get();
        $stores     = $this->catalog->storesQuery($request->storeFilters());
        $products   = $this->catalog->productsQuery($request->productFilters());

        $storesTotal   = (clone $stores)->toBase()->getCountForPagination();
        $productsTotal = (clone $products)->toBase()->getCountForPagination();

        return [
            'total'      => $categories->count() + $storesTotal + $productsTotal,
            'categories' => [
                'items' => CategoryResource::collection($categories),
                'total' => $categories->count(),
            ],
            'stores'     => [
                'items' => StoreListingResource::collection($stores->limit($limit)->get()),
                'total' => $storesTotal,
            ],
            'products'   => [
                'items' => ProductListingResource::collection($products->limit($limit)->get()),
                'total' => $productsTotal,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function stores(SearchRequest $request): array
    {
        $paginator = $this->catalog->stores($request->storeFilters(), $this->perPage($request));

        return ['total' => $paginator->total()]
            + $this->paginated($paginator, StoreListingResource::collection($paginator));
    }

    /**
     * @return array<string, mixed>
     */
    protected function products(SearchRequest $request): array
    {
        $paginator = $this->catalog->products($request->productFilters(), $this->perPage($request));

        return ['total' => $paginator->total()]
            + $this->paginated($paginator, ProductListingResource::collection($paginator));
    }

    /**
     * @return array<string, mixed>
     */
    protected function categories(SearchRequest $request): array
    {
        $paginator = $this->catalog->categoriesQuery($request->keyword())->paginate($this->perPage($request));

        return ['total' => $paginator->total()]
            + $this->paginated($paginator, CategoryResource::collection($paginator));
    }

    protected function remember(?User $user, string $keyword, string $type, int $total, bool $save): void
    {
        if ($user && $save) {
            SearchHistory::record($user, $keyword, $type, $total);
        }
    }
}
