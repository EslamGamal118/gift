<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\AddressRequest;
use App\Http\Resources\Checkout\UserAddressResource;
use App\Models\UserAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Saved addresses of the authenticated user: a customer's delivery addresses
 * or a personal shopper's pickup addresses. Every action is scoped to the
 * signed-in account (another user's address is a 404).
 */
class AddressController extends Controller
{
    /**
     * GET /api/v1/addresses
     */
    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success('messages.success', UserAddressResource::collection($addresses));
    }

    /**
     * POST /api/v1/addresses
     */
    public function store(AddressRequest $request): JsonResponse
    {
        $user = $request->user();

        $address = DB::transaction(function () use ($request, $user) {
            $data = $request->validated();

            // The first address becomes the default one.
            $makeDefault = $user->addresses()->doesntExist() || (bool) ($data['is_default'] ?? false);

            /** @var UserAddress $address */
            $address = $user->addresses()->create($data + ['is_default' => false]);

            if ($makeDefault) {
                $address->markAsDefault();
            }

            return $address;
        });

        return ApiResponse::send(201, 'checkout.address_created', new UserAddressResource($address->refresh()));
    }

    /**
     * GET /api/v1/addresses/{address}
     */
    public function show(Request $request, int $address): JsonResponse
    {
        return ApiResponse::success('messages.success', new UserAddressResource($this->find($request, $address)));
    }

    /**
     * PUT /api/v1/addresses/{address}
     */
    public function update(AddressRequest $request, int $address): JsonResponse
    {
        $address = $this->find($request, $address);

        DB::transaction(function () use ($request, $address) {
            $data = $request->validated();

            // The default address cannot be un-defaulted directly; set another one instead.
            if (array_key_exists('is_default', $data) && ! $data['is_default'] && $address->is_default) {
                unset($data['is_default']);
            }

            $address->fill($data)->save();

            if ($address->is_default) {
                $address->markAsDefault();
            }
        });

        return ApiResponse::success('checkout.address_updated', new UserAddressResource($address->refresh()));
    }

    /**
     * POST /api/v1/addresses/{address}/default
     */
    public function setDefault(Request $request, int $address): JsonResponse
    {
        $address = $this->find($request, $address);
        $address->markAsDefault();

        return ApiResponse::success('checkout.address_updated', new UserAddressResource($address->refresh()));
    }

    /**
     * DELETE /api/v1/addresses/{address}
     */
    public function destroy(Request $request, int $address): JsonResponse
    {
        $user = $request->user();
        $address = $this->find($request, $address);

        DB::transaction(function () use ($user, $address) {
            $wasDefault = $address->is_default;

            $address->delete(); // carts.address_id is nulled by the FK

            if ($wasDefault) {
                $user->addresses()->orderByDesc('id')->first()?->markAsDefault();
            }
        });

        return ApiResponse::success('checkout.address_deleted');
    }

    protected function find(Request $request, int $id): UserAddress
    {
        // Scoping through the relationship yields a 404 for other customers' addresses.
        return $request->user()->addresses()->findOrFail($id);
    }
}
