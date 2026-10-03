<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use App\Models\NotificationContent;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The signed-in account's notifications inbox (any account type).
 */
class NotificationController extends Controller
{
    use PaginatesResults;

    public function __construct(protected NotificationService $notifications) {}

    /**
     * GET /api/v1/notifications?type=all|orders|payments|system&page=&per_page=
     *
     * Newest first, each with its date group (today / yesterday / older) for
     * the section headers; `unread_count` overall and per filter.
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->validate([
            'type' => ['nullable', Rule::in(['all', ...NotificationContent::CATEGORIES])],
        ])['type'] ?? 'all';

        $user = $request->user();
        $page = $this->notifications->inbox($user, $type === 'all' ? null : $type, $this->perPage($request));
        $counts = $this->notifications->unreadCounts($user);

        return ApiResponse::success('messages.success', [
            'type' => $type,
            'filters' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => __('notifications.inbox.filters.'.$key),
                'unread_count' => $counts[$key],
            ], ['all', ...NotificationContent::CATEGORIES]),
            'unread_count' => $counts['all'],
        ] + $this->paginated($page, NotificationResource::collection($page)));
    }

    /**
     * POST /api/v1/notifications/mark-all-read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = $this->notifications->markAllAsRead($request->user());

        return ApiResponse::success('notifications.inbox.all_marked_read', [
            'updated_count' => $updated,
            'unread_count' => 0,
        ]);
    }

    /**
     * POST /api/v1/notifications/{notification}/read  (one notification tapped)
     */
    public function markAsRead(Request $request, int $notification): JsonResponse
    {
        /** @var AppNotification $row */
        $row = AppNotification::query()->for($request->user())->with('content')->findOrFail($notification);
        $this->notifications->markAsRead($row);

        return ApiResponse::success('messages.success', [
            'notification' => new NotificationResource($row),
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }
}
