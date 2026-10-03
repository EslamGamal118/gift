<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\StoreSearchHistoryRequest;
use App\Http\Resources\SearchHistoryResource;
use App\Models\SearchHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recent searches of the authenticated user.
 */
class SearchHistoryController extends Controller
{
    /**
     * GET /api/v1/search/history
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) config('stores.search.history_limit', 20);

        $history = $request->user()->searchHistories()
            ->recent()
            ->limit($limit)
            ->get();

        return ApiResponse::success('messages.success', SearchHistoryResource::collection($history));
    }

    /**
     * POST /api/v1/search/history
     */
    public function store(StoreSearchHistoryRequest $request): JsonResponse
    {
        $entry = SearchHistory::record(
            $request->user(),
            $request->validated('keyword'),
            $request->validated('type'),
            $request->validated('results_count'),
        );

        return ApiResponse::send($entry->wasRecentlyCreated ? 201 : 200, 'search.history_saved', new SearchHistoryResource($entry));
    }

    /**
     * DELETE /api/v1/search/history/{history}
     */
    public function destroy(Request $request, int $history): JsonResponse
    {
        // Scoping through the relationship yields a 404 for entries of other users.
        $request->user()->searchHistories()->findOrFail($history)->delete();

        return ApiResponse::success('search.history_deleted');
    }

    /**
     * DELETE /api/v1/search/history
     */
    public function clear(Request $request): JsonResponse
    {
        $request->user()->searchHistories()->delete();

        return ApiResponse::success('search.history_cleared');
    }
}
