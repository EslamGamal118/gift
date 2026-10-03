<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public home screen banners / sliders.
 * Text fields are returned in the locale resolved from the Accept-Language header.
 */
class BannerController extends Controller
{
    /**
     * GET /api/v1/banners
     */
    public function index(Request $request): JsonResponse
    {
        $banners = Banner::query()
            ->active()
            ->ordered()
            ->get();

        return ApiResponse::success('messages.success', BannerResource::collection($banners));
    }
}
