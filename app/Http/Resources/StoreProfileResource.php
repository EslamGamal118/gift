<?php

namespace App\Http\Resources;

use App\Http\Resources\Profile\ProfileSectionsResource;
use App\Models\StoreProfile;

/**
 * Store profile sections: store details, documents, working hours and branches.
 * Expects `category`, `mainBranch`, `branches` and the branches count to be loaded.
 *
 * @mixin StoreProfile
 */
class StoreProfileResource extends ProfileSectionsResource
{
    protected function details(): array
    {
        return [
            'store_name'          => $this->store_name,
            'phone'               => $this->phone,
            'email'               => $this->email,
            'category_id'         => $this->category_id,
            'category'            => $this->category ? new CategoryResource($this->category) : null,
            'description'         => $this->description,
            'logo'                => $this->fileUrl($this->logo),
            'cover_image'         => $this->fileUrl($this->cover_image),
            'commercial_register' => $this->document('commercial_register', $this->commercial_register_file),
        ];
    }

    protected function documentFiles(): array
    {
        return ['commercial_register' => $this->commercial_register_file];
    }

    /**
     * Every day in display order (Saturday first), closed days without times.
     */
    protected function workingHours(): array
    {
        $hours = $this->working_hours ?? [];

        return [
            'is_set'      => $hours !== [],
            'is_open_now' => $hours !== [] && $this->resource->isOpenNow(),
            'days'        => collect(StoreProfile::DAYS)->map(function (string $day) use ($hours) {
                $isOpen = filter_var($hours[$day]['is_open'] ?? false, FILTER_VALIDATE_BOOLEAN);

                return [
                    'day'     => $day,
                    'label'   => __('profile.days.'.$day),
                    'is_open' => $isOpen,
                    'from'    => $isOpen ? ($hours[$day]['from'] ?? null) : null,
                    'to'      => $isOpen ? ($hours[$day]['to'] ?? null) : null,
                ];
            })->all(),
        ];
    }

    /**
     * The store's location is its main branch; all branches are listed below it.
     */
    protected function location(): array
    {
        $main = $this->mainBranch;

        return $this->point($main?->address, $main?->latitude, $main?->longitude) + [
            'main_branch'    => $main ? new StoreBranchResource($main) : null,
            'branches_count' => (int) ($this->branches_count ?? $this->branches->count()),
            'branches'       => StoreBranchResource::collection($this->branches),
        ];
    }
}
