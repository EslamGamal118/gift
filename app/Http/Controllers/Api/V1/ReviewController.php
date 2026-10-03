<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;

/**
 * The customer's review of a finished order. One action for both kinds of
 * order; the route says which one (`type` route default), since store order
 * and custom order ids overlap.
 */
class ReviewController extends Controller
{
    public function __construct(protected ReviewService $reviews) {}

    /**
     * POST /api/v1/orders/{id}/review         (delivered store order)
     * POST /api/v1/custom-orders/{id}/review  (completed custom order)
     * { store_rating, store_comment?, products_rating, products_comment? }
     *
     * 201 with the review; 404 for another customer's order; 409 before the
     * order is finished or when it was already reviewed.
     */
    public function store(StoreReviewRequest $request, int $id, string $type): JsonResponse
    {
        $review = $this->reviews->submit($request->user(), $type, $id, $request->review());

        return ApiResponse::send(201, 'reviews.submitted', new ReviewResource($review));
    }
}
