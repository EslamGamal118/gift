<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Catalog\Concerns\ResolvesCustomerLocation;

/**
 * Store screen: header + tabs + first page of products + recent reviews.
 *
 * GET /stores/{store}?latitude=..&longitude=..|city=..&tab=best_sellers&sort=..&page=..&per_page=10
 *
 * The position only feeds the header's distance / delivery fee / delivery
 * time. Without coordinates the signed-in customer's cached last GPS position
 * or saved address is used (as on the home screen).
 */
class StoreShowRequest extends StoreProductsRequest
{
    use ResolvesCustomerLocation;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + $this->locationRules();
    }

    /**
     * Same as home and category listings: cache a signed-in customer's GPS
     * position and fall back to it when coordinates are missing.
     */
    protected function remembersLocation(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return parent::attributes() + $this->locationAttributes();
    }
}
