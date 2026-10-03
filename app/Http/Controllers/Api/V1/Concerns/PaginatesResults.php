<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consistent `items` + `pagination` payload for paginated list endpoints,
 * ready to be passed as the `data` of an ApiResponse.
 */
trait PaginatesResults
{
    protected int $defaultPerPage = 15;
    protected int $maxPerPage     = 100;

    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', $this->defaultPerPage);

        return max(1, min($perPage, $this->maxPerPage));
    }

    /**
     * @return array<string, mixed>
     */
    protected function paginated(LengthAwarePaginator $paginator, ResourceCollection $items): array
    {
        return [
            'items'      => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'has_more'     => $paginator->hasMorePages(),
            ],
        ];
    }
}
