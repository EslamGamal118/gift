<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\Concerns\ResolvesFileUrls;
use App\Models\CaptainProfile;
use App\Models\ShopperProfile;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A store / captain / personal-shopper profile laid out as the profile screen's sections:
 *
 *   basic_info     owner data: name, verified mobile, email, avatar, IBAN + certificate (all roles)
 *   details        store data / vehicle data / activity data (per role)
 *   documents      every uploaded document, each with its upload field `key`
 *   working_hours  weekly schedule (stores; null for other roles)
 *   location       map location (+ branches for stores)
 *
 * Every document is `{key, label, url, file_type, is_uploaded}` where `key` is the field
 * to send to /profile/update to replace it. The owner's account is read from the `user`
 * relation (set by the caller to avoid a query).
 *
 * @mixin StoreProfile|CaptainProfile|ShopperProfile
 */
abstract class ProfileSectionsResource extends JsonResource
{
    use ResolvesFileUrls;

    /**
     * @return array<string, mixed>
     */
    abstract protected function details(): array;

    /**
     * Role documents after the IBAN certificate, as [upload key => stored path].
     *
     * @return array<string, ?string>
     */
    abstract protected function documentFiles(): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function location(): array;

    /**
     * @return array<string, mixed>|null
     */
    protected function workingHours(): ?array
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'status'           => $this->status,
            'is_approved'      => $this->isApproved(),
            'is_pending'       => $this->isPending(),
            'is_rejected'      => $this->status === 'rejected',
            'rejection_reason' => $this->status === 'rejected' ? $this->rejection_reason : null,
            'submitted_at'     => $this->submitted_at?->toIso8601String(),

            'basic_info'    => $this->basicInfo(),
            'details'       => $this->details(),
            'documents'     => $this->documents(),
            'working_hours' => $this->workingHours(),
            'location'      => $this->location(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function basicInfo(): array
    {
        /** @var User|null $user */
        $user = $this->resource->user;

        return [
            'name'             => $user?->name,
            'phone'            => $user?->phone,
            'phone_verified'   => (bool) $user?->hasVerifiedPhone(),
            'email'            => $user?->email,
            'avatar'           => $this->fileUrl($user?->avatar ?: $this->resource->getAttribute('personal_photo')),
            'iban'             => $this->iban,
            'iban_certificate' => $this->document('iban_certificate', $this->iban_certificate_file),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function documents(): array
    {
        $files = ['iban_certificate' => $this->iban_certificate_file] + $this->documentFiles();

        return collect($files)
            ->map(fn (?string $path, string $key) => $this->document($key, $path))
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, label: string, url: ?string, file_type: ?string, is_uploaded: bool}
     */
    protected function document(string $key, ?string $path): array
    {
        $extension = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : null;

        return [
            'key'         => $key,
            'label'       => __('validation.attributes.'.$key),
            'url'         => $this->fileUrl($path),
            'file_type'   => match (true) {
                $extension === null || $extension === '' => null,
                $extension === 'pdf'                     => 'pdf',
                default                                  => 'image',
            },
            'is_uploaded' => filled($path),
        ];
    }

    /**
     * @return array{address: ?string, latitude: ?float, longitude: ?float, is_set: bool}
     */
    protected function point(?string $address, mixed $latitude, mixed $longitude): array
    {
        return [
            'address'   => $address,
            'latitude'  => $latitude !== null ? (float) $latitude : null,
            'longitude' => $longitude !== null ? (float) $longitude : null,
            'is_set'    => filled($address) && $latitude !== null && $longitude !== null,
        ];
    }
}
