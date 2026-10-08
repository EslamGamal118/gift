<?php

namespace App\Services;

use App\Http\Resources\Checkout\OrderDetailsResource;
use App\Models\CustomOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The customer's "My orders" screens: store orders (/user/orders) and custom
 * (personal shopper) orders (/user/custom-orders), each newest first, split
 * into tabs and searchable with the same keyword rules (matchingKeyword scope).
 *
 * Unpaid store orders (`pending_payment`) and custom-order drafts belong to
 * no tab and are never listed here.
 */
class UserOrderService
{
    public const TYPE_STANDARD = 'standard';

    public const TYPE_CUSTOM = 'custom';

    public const TYPES = [self::TYPE_STANDARD, self::TYPE_CUSTOM];

    public const TAB_ACTIVE = 'active';

    public const TAB_HISTORY = 'history';

    public const TABS = [self::TAB_ACTIVE, self::TAB_HISTORY];

    /**
     * Statuses shown in each tab, per order type.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const TAB_STATUSES = [
        self::TAB_ACTIVE => [
            self::TYPE_STANDARD => Order::ACTIVE_STATUSES,
            self::TYPE_CUSTOM   => CustomOrder::ACTIVE_STATUSES,
        ],
        self::TAB_HISTORY => [
            self::TYPE_STANDARD => Order::HISTORY_STATUSES,
            self::TYPE_CUSTOM   => CustomOrder::HISTORY_STATUSES,
        ],
    ];

    public function __construct(protected CustomOrderService $customOrders) {}

    /**
     * One page of the customer's store orders; `null` tab = both tabs.
     */
    public function listStandard(User $customer, ?string $tab, int $perPage, ?string $search = null): LengthAwarePaginator
    {
        return $this->paginate(Order::query()->forUser($customer->id)->with('items'), self::TYPE_STANDARD, $tab, $perPage, $search);
    }

    /**
     * One page of the customer's custom orders; `null` tab = both tabs.
     */
    public function listCustom(User $customer, ?string $tab, int $perPage, ?string $search = null): LengthAwarePaginator
    {
        return $this->paginate(CustomOrder::query()->forCustomer($customer->id)->with(['items.media', 'shopper:id,name,avatar', 'shopperProfile']), self::TYPE_CUSTOM, $tab, $perPage, $search);
    }

    /**
     * The list's tabs ("الحالية" / "السابقة") with how many orders each holds
     * for the same keyword.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function tabs(User $customer, string $type, ?string $search = null): array
    {
        $query = $type === self::TYPE_CUSTOM
            ? CustomOrder::query()->forCustomer($customer->id)
            : Order::query()->forUser($customer->id);

        return array_map(fn (string $tab) => [
            'key'   => $tab,
            'label' => __('orders.tabs.'.$tab),
            'count' => (clone $query)->whereIn('status', self::TAB_STATUSES[$tab][$type])->matchingKeyword($search)->count(),
        ], self::TABS);
    }

    /**
     * One of the customer's orders of the given type, loaded for the details
     * screen, or 404. Unlike the list, drafts and unpaid orders are reachable.
     *
     * Address and delivery slot are rendered from the snapshots on the order,
     * so the saved address / slot rows are not loaded.
     *
     * @throws ModelNotFoundException
     */
    public function findForCustomer(User $customer, string $type, int $id): Order|CustomOrder
    {
        if ($type === self::TYPE_CUSTOM) {
            return $this->customOrders->findForCustomer($customer, $id);
        }

        return $customer->orders()
            ->with(OrderDetailsResource::RELATIONS)
            ->findOrFail($id);
    }

    /**
     * Status of a listed order mapped to its tab.
     */
    public static function tabFor(string $type, string $status): ?string
    {
        foreach (self::TAB_STATUSES as $tab => $statuses) {
            if (in_array($status, $statuses[$type] ?? [], true)) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * Tab + keyword filter, newest first (id breaks ties so pages are stable);
     * each item gets its `order_type` for UserOrderResource.
     */
    protected function paginate(Builder $query, string $type, ?string $tab, int $perPage, ?string $search): LengthAwarePaginator
    {
        $statuses = $tab !== null
            ? self::TAB_STATUSES[$tab][$type]
            : array_merge(self::TAB_STATUSES[self::TAB_ACTIVE][$type], self::TAB_STATUSES[self::TAB_HISTORY][$type]);

        $paginator = $query
            ->whereIn('status', $statuses)
            ->matchingKeyword($search)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $paginator->getCollection()->each->setAttribute('order_type', $type);

        return $paginator;
    }
}
