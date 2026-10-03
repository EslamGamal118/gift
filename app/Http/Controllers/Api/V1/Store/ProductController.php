<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreProductRequest;
use App\Http\Requests\Store\UpdateProductRequest;
use App\Http\Resources\AddonResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\FileUploadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Product management for the authenticated merchant's store.
 * The route group enforces the `store` role; products are always scoped to the caller's account.
 */
class ProductController extends Controller
{
    use PaginatesResults;

    public function __construct(
        protected FileUploadService $files,
    ) {
    }

    /**
     * GET /api/v1/store/products
     *
     * Filters: search (name), category_id, in_stock (bool), expired (bool).
     * Sorting: sort=newest|oldest|price_asc|price_desc|name (default: newest).
     */
    public function index(Request $request): JsonResponse
    {
        $products = $request->user()->products()
            ->with('category')
            ->when($request->filled('search'), fn (Builder $q) => $q->searchName($request->string('search')->trim()->toString()))
            ->when($request->filled('category_id'), fn (Builder $q) => $q->inCategory($request->integer('category_id')))
            ->when($request->boolean('in_stock'), fn (Builder $q) => $q->inStock())
            ->when($request->has('expired'), function (Builder $q) use ($request) {
                $request->boolean('expired')
                    ? $q->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString())
                    : $q->notExpired();
            })
            ->tap(fn (Builder $q) => $this->applySort($q, $request->input('sort')))
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return ApiResponse::success('messages.success', $this->paginated($products, ProductResource::collection($products)));
    }

    /**
     * POST /api/v1/store/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $user      = $request->user();
        $imagePath = null;

        try {
            $product = DB::transaction(function () use ($request, $user, &$imagePath) {
                $data = $request->productData();

                if ($image = $request->imageFile()) {
                    $data['image'] = $imagePath = $this->files->replace($image, $this->directoryFor($user));
                }

                return $user->products()->create($data);
            });
        } catch (Throwable $e) {
            // Do not leave an orphaned upload behind if persisting the product failed.
            $this->files->delete($imagePath);

            throw $e;
        }

        return ApiResponse::send(201, 'store.product_created', new ProductResource($product->load('category')));
    }

    /**
     * GET /api/v1/store/products/{product}
     */
    public function show(Request $request, int $product): JsonResponse
    {
        $product = $this->findProduct($request->user(), $product)->load('category');
        $product->setRelation('addons', $product->availableAddons()->orderBy('name')->get());

        return ApiResponse::success('messages.success', new ProductResource($product));
    }

    /**
     * PUT|PATCH|POST /api/v1/store/products/{product}
     * POST is accepted so multipart bodies (image upload) work from mobile clients.
     */
    public function update(UpdateProductRequest $request, int $product): JsonResponse
    {
        $user    = $request->user();
        $product = $this->findProduct($user, $product);

        DB::transaction(function () use ($request, $user, $product) {
            $data = $request->productData();

            if ($image = $request->imageFile()) {
                $data['image'] = $this->files->replace($image, $this->directoryFor($user), $product->image);
            }

            $product->fill($data)->save();
        });

        return ApiResponse::success('store.product_updated', new ProductResource($product->refresh()->load('category')));
    }

    /**
     * DELETE /api/v1/store/products/{product}
     */
    public function destroy(Request $request, int $product): JsonResponse
    {
        $product = $this->findProduct($request->user(), $product);

        DB::transaction(function () use ($product) {
            $product->delete();

            $this->files->deleteAfterCommit($product->image);
        });

        return ApiResponse::success('store.product_deleted');
    }

    /**
     * GET /api/v1/store/products/{product}/addons
     *
     * Active add-ons of this store that apply to the product's category.
     */
    public function addons(Request $request, int $product): JsonResponse
    {
        $product = $this->findProduct($request->user(), $product);

        $addons = $product->availableAddons()
            ->with('categories')
            ->orderBy('name')
            ->get();

        return ApiResponse::success('messages.success', AddonResource::collection($addons));
    }

    protected function applySort(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'oldest'     => $query->orderBy('id'),
            'price_asc'  => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            'name'       => $query->orderBy('name')->orderByDesc('id'),
            default      => $query->orderByDesc('id'),
        };
    }

    protected function findProduct(User $user, int $id): Product
    {
        // Scoping through the relationship yields a 404 for products of other stores.
        return $user->products()->findOrFail($id);
    }

    protected function directoryFor(User $user): string
    {
        return "stores/{$user->id}/products";
    }
}
