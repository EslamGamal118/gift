<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreAddonRequest;
use App\Http\Requests\Store\SyncAddonCategoriesRequest;
use App\Http\Requests\Store\UpdateAddonRequest;
use App\Http\Resources\AddonResource;
use App\Models\Addon;
use App\Models\User;
use App\Services\FileUploadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Add-on management for the authenticated merchant's store, including the
 * categories each add-on applies to. Add-ons are always scoped to the caller's account.
 */
class AddonController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected FileUploadService $files,
    ) {
    }

    /**
     * GET /api/v1/store/addons
     *
     * Filters: search (name), category_id, is_active (bool), in_stock (bool).
     */
    public function index(Request $request): JsonResponse
    {
        $addons = $request->user()->addons()
            ->with('categories')
            ->when($request->filled('search'), fn (Builder $q) => $q->searchName($request->string('search')->trim()->toString()))
            ->when($request->filled('category_id'), fn (Builder $q) => $q->forCategory($request->integer('category_id')))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->boolean('in_stock'), fn (Builder $q) => $q->inStock())
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return ApiResponse::success('messages.success', $this->paginated($addons, AddonResource::collection($addons)));
    }

    /**
     * POST /api/v1/store/addons
     */
    public function store(StoreAddonRequest $request): JsonResponse
    {
        $user      = $request->user();
        $imagePath = null;

        try {
            $addon = DB::transaction(function () use ($request, $user, &$imagePath) {
                $data = $request->addonData() + ['is_active' => true];

                if ($image = $request->imageFile()) {
                    $data['image'] = $imagePath = $this->files->replace($image, $this->directoryFor($user));
                }

                /** @var Addon $addon */
                $addon = $user->addons()->create($data);
                $addon->categories()->sync($request->categoryIds() ?? []);

                return $addon;
            });
        } catch (Throwable $e) {
            // Do not leave an orphaned upload behind if persisting the add-on failed.
            $this->files->delete($imagePath);

            throw $e;
        }

        return ApiResponse::send(201, 'store.addon_created', new AddonResource($addon->load('categories')));
    }

    /**
     * GET /api/v1/store/addons/{addon}
     */
    public function show(Request $request, int $addon): JsonResponse
    {
        $addon = $this->findAddon($request->user(), $addon)->load('categories');

        return ApiResponse::success('messages.success', new AddonResource($addon));
    }

    /**
     * PUT|PATCH|POST /api/v1/store/addons/{addon}
     * POST is accepted so multipart bodies (image upload) work from mobile clients.
     */
    public function update(UpdateAddonRequest $request, int $addon): JsonResponse
    {
        $user  = $request->user();
        $addon = $this->findAddon($user, $addon);

        DB::transaction(function () use ($request, $user, $addon) {
            $data = $request->addonData();

            if ($image = $request->imageFile()) {
                $data['image'] = $this->files->replace($image, $this->directoryFor($user), $addon->image);
            }

            $addon->fill($data)->save();

            // Category links are replaced only when the client sends them.
            if (($categoryIds = $request->categoryIds()) !== null) {
                $addon->categories()->sync($categoryIds);
            }
        });

        return ApiResponse::success('store.addon_updated', new AddonResource($addon->refresh()->load('categories')));
    }

    /**
     * PUT /api/v1/store/addons/{addon}/categories
     *
     * Replace the categories this add-on applies to.
     */
    public function syncCategories(SyncAddonCategoriesRequest $request, int $addon): JsonResponse
    {
        $addon = $this->findAddon($request->user(), $addon);

        DB::transaction(fn () => $addon->categories()->sync($request->categoryIds()));

        return ApiResponse::success('store.addon_categories_updated', new AddonResource($addon->load('categories')));
    }

    /**
     * DELETE /api/v1/store/addons/{addon}
     */
    public function destroy(Request $request, int $addon): JsonResponse
    {
        $addon = $this->findAddon($request->user(), $addon);

        DB::transaction(function () use ($addon) {
            // Pivot rows are removed by the database (cascadeOnDelete on category_addon).
            $addon->delete();

            $this->files->deleteAfterCommit($addon->image);
        });

        return ApiResponse::success('store.addon_deleted');
    }

    protected function findAddon(User $user, int $id): Addon
    {
        // Scoping through the relationship yields a 404 for add-ons of other stores.
        return $user->addons()->findOrFail($id);
    }

    protected function directoryFor(User $user): string
    {
        return "stores/{$user->id}/addons";
    }
}
