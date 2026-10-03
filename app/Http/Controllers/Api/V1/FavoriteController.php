<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Favorite\ListFavoritesRequest;
use App\Http\Requests\Favorite\ToggleFavoriteRequest;
use App\Http\Resources\ProductListingResource;
use App\Http\Resources\StoreListingResource;
use App\Models\Favorite;
use App\Services\FavoriteService;
use Illuminate\Http\JsonResponse;

/**
 * The customer's favorite stores and products.
 */
class FavoriteController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected FavoriteService $favorites,
    ) {
    }

    /**
     * GET /api/v1/favorites?type=store|product - most recently added first.
     */
    public function index(ListFavoritesRequest $request): JsonResponse
    {
        $type = $request->favoritableType();

        $paginator = $this->favorites->paginate($request->user(), $type, $this->perPage($request), $request->location());

        $items = $type === Favorite::TYPE_STORE
            ? StoreListingResource::collection($paginator)
            : ProductListingResource::collection($paginator);

        return ApiResponse::success('messages.success', ['type' => $type] + $this->paginated($paginator, $items));
    }

    /**
     * POST /api/v1/favorites/toggle { favoritable_type, favoritable_id }
     *
     * Adds the item when it is not a favorite yet, removes it otherwise.
     * 404 when adding a store / product that does not exist or is hidden.
     */
    public function toggle(ToggleFavoriteRequest $request): JsonResponse
    {
        $type = $request->favoritableType();
        $id   = $request->favoritableId();

        $isFavorite = $this->favorites->toggle($request->user(), $type, $id);

        return ApiResponse::success($isFavorite ? 'favorites.added' : 'favorites.removed', [
            'favoritable_type' => $type,
            'favoritable_id'   => $id,
            'is_favorite'      => $isFavorite,
        ]);
    }
}
