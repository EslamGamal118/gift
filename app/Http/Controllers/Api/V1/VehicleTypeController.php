<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleTypeResource;
use App\Models\VehicleType;
use Illuminate\Http\JsonResponse;

class VehicleTypeController extends Controller
{
    /**
     * GET /api/v1/vehicle-types
     *
     * أنواع المركبات المفعّلة — قائمة اختيار نوع المركبة في خطوة نشاط الكابتن.
     */
    public function index(): JsonResponse
    {
        $types = VehicleType::query()->active()->orderBy('id')->get();

        return ApiResponse::success('messages.success', VehicleTypeResource::collection($types));
    }
}
