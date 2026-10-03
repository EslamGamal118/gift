<?php

namespace App\Providers;

use App\Models\Favorite;
use App\Models\User;
use App\Services\FavoriteService;
use App\Services\GiftService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use App\Models\CustomOrder;
use App\Observers\CustomOrderObserver;
use App\Models\Review;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Memoizes the viewer's favorites, so one instance per request.
        $this->app->scoped(FavoriteService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short, stable aliases (store / product) in `favorites.favoritable_type`.
        Relation::morphMap(Favorite::TYPES);
        Relation::morphMap(Review::TYPES);   // reviews.reviewable_type: order | custom_order

        // Paid online gifts sent to a phone number are claimed by the customer
        // account registered with it (OTP sign-up creates the account).
        User::created(fn (User $user) => app(GiftService::class)->claimPendingFor($user));

        // Paid -> notify the shopper; cancelled while paid -> refund (after commit)
        CustomOrder::observe(CustomOrderObserver::class);
    }
}
