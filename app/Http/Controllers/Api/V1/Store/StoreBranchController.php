<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreBranchRequest;
use App\Http\Resources\StoreBranchResource;
use App\Models\StoreBranch;
use App\Models\StoreProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Branch management for the authenticated merchant's store.
 * The route group enforces the `store` role; branches are always scoped to the caller's store.
 */
class StoreBranchController extends Controller
{
    /**
     * GET /api/v1/store/branches
     */
    public function index(Request $request): JsonResponse
    {
        $branches = $this->storeProfile($request)
            ->branches()
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->get();

        return ApiResponse::success('messages.success', StoreBranchResource::collection($branches));
    }

    /**
     * POST /api/v1/store/branches
     */
    public function store(StoreBranchRequest $request): JsonResponse
    {
        $store = $request->storeProfile();

        $branch = DB::transaction(function () use ($request, $store) {
            $data = $request->validated();

            // The first branch is always the main one.
            $makeMain = $store->branches()->doesntExist() || (bool) ($data['is_main'] ?? false);

            /** @var StoreBranch $branch */
            $branch = $store->branches()->create($data + ['is_main' => false, 'is_active' => true]);

            if ($makeMain) {
                $branch->markAsMain();
            }

            return $branch;
        });

        return ApiResponse::send(201, 'profile.branch_created', new StoreBranchResource($branch->refresh()));
    }

    /**
     * PUT /api/v1/store/branches/{branch}
     */
    public function update(StoreBranchRequest $request, int $branch): JsonResponse
    {
        $branch = $this->findBranch($request->storeProfile(), $branch);

        DB::transaction(function () use ($request, $branch) {
            $data = $request->validated();

            // A main branch cannot be demoted directly; promote another branch instead.
            if (array_key_exists('is_main', $data) && ! $data['is_main'] && $branch->is_main) {
                unset($data['is_main']);
            }

            $branch->fill($data)->save();

            if ($branch->is_main) {
                $branch->markAsMain();
            }
        });

        return ApiResponse::success('profile.branch_updated', new StoreBranchResource($branch->refresh()));
    }

    /**
     * DELETE /api/v1/store/branches/{branch}
     */
    public function destroy(Request $request, int $branch): JsonResponse
    {
        $store  = $this->storeProfile($request);
        $branch = $this->findBranch($store, $branch);

        DB::transaction(function () use ($store, $branch) {
            $wasMain = $branch->is_main;

            $branch->delete();

            // Keep exactly one main branch while any branch remains.
            if ($wasMain) {
                $store->branches()->orderBy('id')->first()?->markAsMain();
            }
        });

        return ApiResponse::success('profile.branch_deleted');
    }

    protected function storeProfile(Request $request): StoreProfile
    {
        return $request->user()->storeProfile
            ?? throw \App\Exceptions\ProfileStepException::basicInfoRequired();
    }

    protected function findBranch(StoreProfile $store, int $id): StoreBranch
    {
        // Scoping through the relationship yields a 404 for branches of other stores.
        return $store->branches()->findOrFail($id);
    }
}
