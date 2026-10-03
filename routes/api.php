<?php

use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Checkout\AddressController;
use App\Http\Controllers\Api\V1\Checkout\CartController;
use App\Http\Controllers\Api\V1\Checkout\CheckoutController;
use App\Http\Controllers\Api\V1\Checkout\CustomOrderPaymentController;
use App\Http\Controllers\Api\V1\Checkout\DeliveryController;
use App\Http\Controllers\Api\V1\Checkout\OrderController;
use App\Http\Controllers\Api\V1\CustomOrder\CustomOrderController;
use App\Http\Controllers\Api\V1\CustomOrder\ShopperController as CustomOrderShopperController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\NearbyStoreController;
use App\Http\Controllers\Api\V1\Order\UserCustomOrdersController;
use App\Http\Controllers\Api\V1\Order\UserOrdersController;
use App\Http\Controllers\Api\V1\Payment\AlRajhiController;
use App\Http\Controllers\Api\V1\Payment\CheckoutPaymentController;
use App\Http\Controllers\Api\V1\Payment\PaymentController;
use App\Http\Controllers\Api\V1\Payment\TabbyController;
use App\Http\Controllers\Api\V1\Payment\TamaraController;
use App\Http\Controllers\Api\V1\ProductDetailsController;
use App\Http\Controllers\Api\V1\Profile\LocationController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SearchHistoryController;
use App\Http\Controllers\Api\V1\Shopper\ShopperHomeController;
use App\Http\Controllers\Api\V1\Shopper\ShopperStatisticsController;
use App\Http\Controllers\Api\V1\Shopper\ShopperOrderController;
use App\Http\Controllers\Api\V1\Store\AddonController;
use App\Http\Controllers\Api\V1\Store\ProductController;
use App\Http\Controllers\Api\V1\Store\StoreBranchController;
use App\Http\Controllers\Api\V1\Store\StoreDashboardController;
use App\Http\Controllers\Api\V1\Store\StoreGiftController;
use App\Http\Controllers\Api\V1\Store\StoreOrderController;
use App\Http\Controllers\Api\V1\Store\StoreStatisticsController;
use App\Http\Controllers\Api\V1\Store\StoreOrderStatusController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\GiftController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\VehicleTypeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\FaqController;
use App\Http\Controllers\Api\V1\LegalPageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ReviewFeedController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Models\LegalPage;
use App\Models\Review;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Authentication (OTP) - shared by all account types
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('send-otp', [OtpAuthController::class, 'sendOtp'])
            ->middleware('throttle:otp-send')
            ->name('send-otp');

        Route::post('verify-otp', [OtpAuthController::class, 'verifyOtp'])
            ->middleware('throttle:otp-verify')
            ->name('verify-otp');

        Route::post('logout', [OtpAuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('logout');
    });
    // Customer home screen (public; token optional). Position: ?latitude&longitude or ?city
    Route::get('home', [HomeController::class, 'index'])->name('home');
    Route::get('cities', [HomeController::class, 'cities'])->name('cities');

    // FAQ screen (public; Accept-Language). ?category=general|orders|payments|delivery|support&search=
    Route::get('faqs', [FaqController::class, 'index'])->name('faqs.index');

    // Contact us (public; token optional: pre-fills and links the message to the account)
    Route::prefix('support')->name('support.')->controller(SupportController::class)->group(function () {
        Route::get('contact-info', 'contactInfo')->name('contact-info');
        Route::post('contact', 'store')->middleware('throttle:contact-form')->name('contact');
    });

    // Legal center (public; Accept-Language, or ?locale=all for every language)
    Route::prefix('legal')->name('legal.')->controller(LegalPageController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{type}', 'show')->whereIn('type', LegalPage::TYPES)->name('show');
    });

    // Customer-facing stores (public; token optional)
    Route::prefix('stores')->name('stores.')->group(function () {
        Route::get('nearby', [NearbyStoreController::class, 'index'])->name('nearby');

        // Store screen: profile + tabs + products + reviews
        Route::controller(StoreController::class)->whereNumber('store')->group(function () {
            Route::get('{store}', 'show')->name('show');
            Route::get('{store}/products', 'products')->name('products');
            Route::get('{store}/reviews', 'reviews')->name('reviews');
        });
    });

    // Product details screen (public; token optional). Position: ?latitude&longitude or ?city
    Route::get('products/{product}', [ProductDetailsController::class, 'show'])
        ->whereNumber('product')
        ->name('products.show');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{category}/listings', [CategoryController::class, 'listings'])->name('categories.listings');
    Route::get('vehicle-types', [VehicleTypeController::class, 'index'])->name('vehicle-types.index');

    // Home screen banners / sliders (localized via Accept-Language)
    Route::get('banners', [BannerController::class, 'index'])->name('banners.index');

    // Global search (public; records recent searches when a token is sent)
    Route::get('search', [SearchController::class, 'index'])->name('search');

    // Recent searches of the authenticated user
    Route::middleware('auth:sanctum')->prefix('search/history')->name('search.history.')
        ->controller(SearchHistoryController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::delete('/', 'clear')->name('clear');
            Route::delete('{history}', 'destroy')->name('destroy');
        });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        // Notifications inbox (every account type)
        Route::prefix('notifications')->name('notifications.')->controller(NotificationController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('mark-all-read', 'markAllAsRead')->name('mark-all-read');
            Route::post('{notification}/read', 'markAsRead')->whereNumber('notification')->name('read');
        });

        // Profile completion steps (store / captain / personal shopper)
        Route::prefix('profile')->name('profile.')->controller(ProfileController::class)->group(function () {
            Route::get('/', 'status')->name('show');
            Route::get('status', 'status')->name('status');                      // account state + next step
            Route::match(['post', 'put'], 'update', 'update')->name('update');   // unified partial update
            Route::post('basic-info', 'basicInfo')->name('basic-info');          // step 1 - all roles
            Route::post('store-details', 'storeDetails')->name('store-details'); // step 2 - store
            Route::post('activity', 'activity')->name('activity');               // step 2 - captain / shopper
            Route::post('submit', 'submit')->name('submit');                     // submit for review
        });

        // Personal shopper area
        Route::prefix('shopper')->name('shopper.')->middleware('role:shopper')->group(function () {
            // Home screen: figures + newest orders waiting for the shopper
            Route::get('home', ShopperHomeController::class)->name('home');
            // Statistics screen: weekly earnings + trend, counters, categories, rating
            Route::get('statistics', ShopperStatisticsController::class)->name('statistics');
            // Reviews screen: ratings received on the shopper's custom orders
            Route::get('reviews', [ReviewFeedController::class, 'shopper'])->name('reviews');

            Route::controller(ShopperOrderController::class)->prefix('orders')->name('orders.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::whereNumber('customOrder')->group(function () {
                    Route::get('{customOrder}', 'show')->name('show');
                    Route::post('{customOrder}/status', 'updateStatus')->name('status');
                    Route::post('{customOrder}/submit-invoice-prices', 'submitInvoice')->name('invoice');
                    Route::post('{customOrder}/alternatives', 'suggestAlternative')->name('alternatives');
                });
            });
        });

        // Merchant (store) area
        Route::prefix('store')->name('store.')->middleware('role:store')->group(function () {
            // Branches (step 3 - store)
            Route::apiResource('branches', StoreBranchController::class)
                ->only(['index', 'store', 'update', 'destroy']);

            // Products
            Route::get('products/{product}/addons', [ProductController::class, 'addons'])->name('products.addons');
            Route::post('products/{product}', [ProductController::class, 'update'])->name('products.update.post'); // multipart updates
            Route::apiResource('products', ProductController::class);

            // Add-ons and their category links
            Route::put('addons/{addon}/categories', [AddonController::class, 'syncCategories'])->name('addons.categories');
            Route::post('addons/{addon}', [AddonController::class, 'update'])->name('addons.update.post'); // multipart updates
            Route::apiResource('addons', AddonController::class);

            // Home screen: live stats, counters, latest paid orders, notification bell
            Route::get('dashboard', StoreDashboardController::class)->name('dashboard');

            // Statistics screen: weekly earnings + charts, counters, distribution, best sellers, rating
            Route::get('statistics', StoreStatisticsController::class)->name('statistics');
            // Online gifts: scan the recipient's QR code to redeem it
            Route::post('gifts/redeem', [StoreGiftController::class, 'redeem'])->middleware('throttle:30,1')->name('gifts.redeem');
            // Reviews screen: ratings the store received from its customers
            Route::get('reviews', [ReviewFeedController::class, 'store'])->name('reviews');

            // Orders received by the store + status workflow
            Route::prefix('orders')->name('orders.')->group(function () {
                Route::get('/', [StoreOrderController::class, 'index'])->name('index');
                Route::get('{order}', [StoreOrderController::class, 'show'])->whereNumber('order')->name('show');

                Route::controller(StoreOrderStatusController::class)->whereNumber('order')->group(function () {
                    Route::post('{order}/status', 'update')->name('status');
                    Route::post('{order}/start-preparing', 'startPreparing')->name('start-preparing');
                    Route::post('{order}/ready', 'ready')->name('ready');
                    Route::post('{order}/dispatch-captain', 'dispatchCaptain')->name('dispatch-captain');
                });
            });
        });

        // Map location confirmation (step 3) - resolved by the account's role
        Route::post('profile/location', LocationController::class)
            ->middleware('role:captain,shopper')
            ->name('profile.location');

        // Saved addresses: the customer's delivery addresses, the personal
        // shopper's pickup addresses (custom orders' pickup_address_id)
        Route::middleware('role:customer,shopper')->group(function () {
            Route::post('addresses/{address}/default', [AddressController::class, 'setDefault'])->name('addresses.default');
            Route::apiResource('addresses', AddressController::class);
        });

        // Customer checkout flow
        Route::middleware('role:customer')->group(function () {
            // Reviews screen: ratings the customer gave (stores and personal shoppers)
            Route::get('user/reviews', [ReviewFeedController::class, 'given'])->name('user.reviews');

            // Favorite stores and products
            Route::prefix('favorites')->name('favorites.')->controller(FavoriteController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('toggle', 'toggle')->name('toggle');
            });

            // Online gifts (special-category products bought for someone else)
            Route::prefix('gifts')->name('gifts.')->controller(GiftController::class)->group(function () {
                Route::get('details/{product}', 'details')->whereNumber('product')->name('details');
                Route::post('checkout', 'checkout')->middleware('throttle:10,1')->name('checkout');
                Route::get('sent', 'sent')->name('sent');
                Route::get('received', 'received')->name('received');
                Route::get('received/{gift}', 'show')->whereNumber('gift')->name('received.show');
                // "Claim a gift" with the 6-digit code texted to the recipient's phone
                Route::post('claim', 'claim')->middleware('throttle:gift-claim')->name('claim');
                // Home screen "You received a new gift" popup buttons
                Route::post('{gift}/open', 'open')->whereNumber('gift')->name('open');
                Route::post('{gift}/dismiss', 'dismiss')->whereNumber('gift')->name('dismiss');
            });

            // Cart (may hold several stores; split into one order per store at checkout)
            Route::prefix('cart')->name('cart.')->controller(CartController::class)->group(function () {
                Route::get('/', 'show')->name('show');
                Route::delete('/', 'clear')->name('clear');
                Route::get('suggested-products', 'suggestedProducts')->name('suggested-products');
                Route::post('items', 'addItem')->name('items.add');
                Route::put('items/{item}', 'updateItem')->name('items.update');
                Route::delete('items/{item}', 'removeItem')->name('items.remove');
            });

            Route::prefix('checkout')->name('checkout.')->group(function () {
                // Delivery time / instant delivery
                Route::controller(DeliveryController::class)->prefix('delivery')->name('delivery.')->group(function () {
                    Route::get('options', 'options')->name('options');
                    Route::get('quote', 'quote')->name('quote');
                    Route::post('/', 'select')->name('select');
                });

                // Review screen
                Route::controller(CheckoutController::class)->group(function () {
                    Route::get('summary', 'summary')->name('summary');
                    Route::post('address', 'selectAddress')->name('address');
                    Route::put('gift-message', 'giftMessage')->name('gift-message');
                    Route::post('promo', 'applyPromo')->name('promo.apply');
                    Route::delete('promo', 'removePromo')->name('promo.remove');
                    Route::post('place-order', 'placeOrder')->name('place-order');
                });

                // Paying a custom (personal shopper) order: priced on the server, existing gateways
                Route::post('pay', [CustomOrderPaymentController::class, 'pay'])->middleware('throttle:10,1')->name('pay');
            });

            // Custom orders (personal shopper): items -> choose shopper / bidding -> confirm delivery -> track
            Route::prefix('custom-orders')->name('custom-orders.')->group(function () {
                Route::get('shoppers', [CustomOrderShopperController::class, 'index'])->name('shoppers');

                Route::controller(CustomOrderController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'store')->name('store');

                    Route::whereNumber('customOrder')->group(function () {
                        Route::get('{customOrder}', 'show')->name('show');
                        Route::post('{customOrder}/assign-shopper', 'assignShopper')->name('assign-shopper');
                        Route::post('{customOrder}/confirm', 'confirm')->name('confirm');
                        Route::post('{customOrder}/cancel', 'cancel')->name('cancel');
                        Route::post('{customOrder}/alternatives/response', 'respondToAlternatives')->name('alternatives.response');
                        Route::get('{customOrder}/payment-status', [CustomOrderPaymentController::class, 'status'])->name('payment-status');
                    });
                });
            });

            // "My orders" screens (?tab=active|history&search=): store orders and custom orders
            Route::prefix('user/orders')->name('user.orders.')->controller(UserOrdersController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{id}', 'show')->whereNumber('id')->name('show');
            });
            Route::prefix('user/custom-orders')->name('user.custom-orders.')->controller(UserCustomOrdersController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{id}', 'show')->whereNumber('id')->name('show');
            });

            // Orders & payment
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

            // Reviews of finished orders (store order / custom order): one controller, the route says which
            Route::post('orders/{id}/review', [ReviewController::class, 'store'])
                ->whereNumber('id')->defaults('type', Review::TYPE_ORDER)->name('orders.review');
            Route::post('custom-orders/{id}/review', [ReviewController::class, 'store'])
                ->whereNumber('id')->defaults('type', Review::TYPE_CUSTOM_ORDER)->name('custom-orders.review');
            Route::post('orders/{order}/pay', [PaymentController::class, 'initiate'])->name('orders.pay');
            Route::get('orders/{order}/payment-status', [PaymentController::class, 'status'])->name('orders.payment-status');

            // A placed checkout: one payment for all of its per-store orders
            Route::prefix('checkouts/{checkout}')->whereNumber('checkout')->name('checkouts.')->controller(CheckoutPaymentController::class)->group(function () {
                Route::get('/', 'show')->name('show');
                Route::post('pay', 'pay')->middleware('throttle:10,1')->name('pay');
                Route::get('payment-status', 'status')->name('payment-status');
            });
        });
    });

    // Gateway return URLs (browser redirects) and webhooks - public, verified inside
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('tabby/{outcome}/{order}', [TabbyController::class, 'handleReturn'])
            ->whereIn('outcome', ['success', 'cancel', 'failure'])
            ->name('tabby.return');
    });

    Route::prefix('webhooks')->name('webhooks.')->group(function () {
        Route::post('tabby', [TabbyController::class, 'webhook'])->name('tabby');
        Route::post('tamara', [TamaraController::class, 'webhook'])->name('tamara');
    });
});

// Return URLs whose names are hard-coded in the existing AlRajhi / Tamara services
Route::match(['get', 'post'], 'v1/payments/alrajhi/callback', [AlRajhiController::class, 'callback'])->name('payment.callback');
Route::match(['get', 'post'], 'v1/payments/alrajhi/failed', [AlRajhiController::class, 'failed'])->name('payment.failed');
Route::get('v1/payments/tamara/success', [TamaraController::class, 'handleReturn'])->defaults('outcome', 'success')->name('tamara.return.success');
Route::get('v1/payments/tamara/failure', [TamaraController::class, 'handleReturn'])->defaults('outcome', 'failure')->name('tamara.return.failure');
Route::get('v1/payments/tamara/cancel', [TamaraController::class, 'handleReturn'])->defaults('outcome', 'cancel')->name('tamara.return.cancel');
