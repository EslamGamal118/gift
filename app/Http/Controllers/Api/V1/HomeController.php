<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Home\HomeRequest;
use App\Http\Resources\CityResource;
use App\Http\Resources\Home\HomeResource;
use App\Services\HomeService;
use App\Support\City;
use Illuminate\Http\JsonResponse;

/**
 * Customer home screen. Public: guests get the same sections with a guest
 * greeting; a Bearer token adds the user block, unread count and, when no
 * coordinates are sent, the last GPS position (cached) or the saved default
 * address as position.
 *
 * Nearby stores are sorted by the road distance to their closest branch and
 * priced by DeliveryCalculatorService (see StoreListingResource).
 */
class HomeController extends Controller
{
    public function __construct(
        protected HomeService $home,
    ) {
    }

    /**
     * GET /api/v1/home?latitude=..&longitude=..|city=riyadh[&within_km=..]
     */
    public function index(HomeRequest $request): JsonResponse
    {
        $payload = $this->home->build(
            $request->user('sanctum'),
            $request->locationOrDefaultCity(),
            $request->radiusKm(),
        );

        return ApiResponse::success('messages.success', new HomeResource($payload));
    }

    /**
     * GET /api/v1/cities - cities a customer can pick when GPS is unavailable.
     */
    public function cities(): JsonResponse
    {
        return ApiResponse::success('messages.success', [
            'default' => config('cities.default'),
            'items'   => CityResource::collection(City::all()),
        ]);
    }
}
