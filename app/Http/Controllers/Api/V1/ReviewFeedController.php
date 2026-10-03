<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewFeedRequest;
use App\Http\Resources\ReviewFeedItemResource;
use App\Services\ReviewFeedService;
use Illuminate\Http\JsonResponse;

/**
 * The "Reviews" screen of each account type, always about the signed-in user
 * (Sanctum token; the role comes from the route's `role:` middleware):
 *
 *  GET /api/v1/user/reviews     customer -> reviews they gave
 *  GET /api/v1/store/reviews    store    -> reviews their store received
 *  GET /api/v1/shopper/reviews  shopper  -> reviews their custom orders received
 */
class ReviewFeedController extends Controller
{
    use PaginatesResults;

    public function __construct(protected ReviewFeedService $feed) {}

    public function given(ReviewFeedRequest $request): JsonResponse
    {
        return $this->respond($request, 'given', null, $this->feed->given($request->user(), $request->filters(), $this->perPage($request)));
    }

    public function store(ReviewFeedRequest $request): JsonResponse
    {
        $profile = $request->user()->storeProfile;

        return $this->respond($request, 'received', $profile ? ['type' => 'store', 'id' => $profile->id, 'name' => $profile->store_name] : null,
            $this->feed->forStore($request->user(), $request->filters(), $this->perPage($request)));
    }

    public function shopper(ReviewFeedRequest $request): JsonResponse
    {
        return $this->respond($request, 'received', ['type' => 'shopper', 'id' => $request->user()->id, 'name' => $request->user()->name],
            $this->feed->forShopper($request->user(), $request->filters(), $this->perPage($request)));
    }

    /**
     * @param  array<string, mixed>|null  $subject
     * @param  array{summary: array<string, mixed>, reviews: \Illuminate\Contracts\Pagination\LengthAwarePaginator}  $feed
     */
    protected function respond(ReviewFeedRequest $request, string $perspective, ?array $subject, array $feed): JsonResponse
    {
        $total = $feed['summary']['total_reviews'];

        return ApiResponse::success('messages.success', [
            'perspective' => $perspective,
            'subject' => $subject,
            'summary' => $feed['summary'] + [
                'total_reviews_label' => trans_choice('statistics.reviews_count', $total, ['count' => $total]),
            ],
            'filters' => $request->filters(),
        ] + $this->paginated($feed['reviews'], ReviewFeedItemResource::collection($feed['reviews'])));
    }
}
