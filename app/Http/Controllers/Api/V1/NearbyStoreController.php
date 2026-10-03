<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\NearbyStoresRequest;
use App\Http\Resources\Home\LocationResource;
use App\Http\Resources\StoreListingResource;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;

/**
 * Stores around the customer, nearest first. Public; the position comes from
 * the query string (GPS or city) or the authenticated customer's default address.
 */
class NearbyStoreController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected CatalogService $catalog,
    ) {
    }

    /**
     * GET /api/v1/stores/nearby?latitude=..&longitude=..[&within_km=10&category_id=3&open_now=1&min_rating=4&search=..]
     */
    public function index(NearbyStoresRequest $request): JsonResponse
    {
        $location = $request->location();
        $filters  = $request->filters();

        $paginator = $this->catalog->nearbyStores($location, $filters, $this->perPage($request));

        return ApiResponse::success('messages.success', [
            'location'  => new LocationResource($location),
            'radius_km' => $this->catalog->nearbyRadiusKm($location, $filters),
            'filters'   => (object) $filters,
        ] + $this->paginated($paginator, StoreListingResource::collection($paginator)));
    }
}
