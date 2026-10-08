<?php

namespace Database\Seeders;

use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Factories\GiftFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Demo online gifts in every payment, claim, redemption and notification
 * state (see GiftFactory), sent between the seeded customers and to people
 * without an account, for the seeded online-gift products.
 *
 * Re-runnable: gifts seeded earlier (their order's `payment_data.demo`) are
 * removed first; gifts bought through the app are kept.
 *
 *   php artisan db:seed --class=GiftSeeder
 */
class GiftSeeder extends Seeder
{
    /**
     * The fixed test customer from UserSeeder: gets gifts in every tab of the app.
     */
    public const DEMO_CUSTOMER_PHONE = '966500000001';

    /**
     * Multiplies every scenario count (1 = about 150 gifts).
     */
    public int $scale = 1;

    public function run(): void
    {
        $senders = User::query()->active()->ofType(User::TYPE_CUSTOMER)->get();
        $products = $this->products();

        if ($senders->count() < 2 || $products->isEmpty()) {
            $this->command?->warn('GiftSeeder: needs customers and products, skipping. Run UserSeeder and ProductSeeder first.');

            return;
        }

        $demo = $senders->firstWhere('phone', self::DEMO_CUSTOMER_PHONE);

        $created = DB::transaction(function () use ($senders, $products, $demo) {
            $this->deleteDemoGifts();

            $count = 0;

            foreach ($this->scenarios() as $row) {
                [$times, $state, $unclaimed] = $row + [2 => false];

                for ($i = 0; $i < $times * $this->scale; $i++) {
                    $recipient = ! $unclaimed && fake()->boolean(30) ? $senders->random() : null;
                    $this->createGift($state, $senders, $products, $recipient);
                    $count++;
                }
            }

            if ($demo) {
                foreach ($this->demoCustomerScenarios() as $state) {
                    $this->createGift($state, $senders, $products, $demo);
                    $count++;
                }
            }

            return $count;
        });

        $this->command?->info("GiftSeeder: {$created} gifts.");
    }

    /**
     * [count, state, unclaimed] — the whole life cycle, the common paths weighted up.
     * Paid gifts left `unclaimed` go to phones with no account: payment attaches
     * any other to the account at once (GiftService::attachExistingRecipient()).
     *
     * @return list<array{0: int, 1: callable(GiftFactory): GiftFactory, 2?: bool}>
     */
    protected function scenarios(): array
    {
        return [
            // Payment never completed
            [10, fn (GiftFactory $f) => $f],                                                  // waiting at the gateway
            [6, fn (GiftFactory $f) => $f->failed()],
            [4, fn (GiftFactory $f) => $f->failed()->via(Order::METHOD_TAMARA)],              // BNPL declined
            [5, fn (GiftFactory $f) => $f->cancelled()],
            [3, fn (GiftFactory $f) => $f->refunded()],

            // Paid, waiting for the recipient to sign up or type the PIN
            [10, fn (GiftFactory $f) => $f->paid(), true],
            [3, fn (GiftFactory $f) => $f->paid()->via(Order::METHOD_ALRAJHI), true],
            [3, fn (GiftFactory $f) => $f->paid()->via(Order::METHOD_TABBY), true],
            [6, fn (GiftFactory $f) => $f->paid()->whatsappFailed(), true],
            [3, fn (GiftFactory $f) => $f->paid()->whatsappFailed()->smsFailed(), true],            // unreachable recipient
            [4, fn (GiftFactory $f) => $f->paid()->smsFailed(), true],
            [3, fn (GiftFactory $f) => $f->paid()->whatsappPending()->smsPending(), true],          // still in the queue
            [2, fn (GiftFactory $f) => $f->paid()->whatsappPending(retrying: true), true],
            [3, fn (GiftFactory $f) => $f->paid()->whatsappSkipped()->smsSkipped(), true],          // channels not configured
            [3, fn (GiftFactory $f) => $f->expiringSoon(), true],

            // In the recipient's account
            [8, fn (GiftFactory $f) => $f->claimed()],                                        // popup not seen yet
            [5, fn (GiftFactory $f) => $f->popupDismissed()],
            [5, fn (GiftFactory $f) => $f->claimedWithPin()],
            [3, fn (GiftFactory $f) => $f->claimed()->whatsappFailed()],
            [10, fn (GiftFactory $f) => $f->opened()],
            [3, fn (GiftFactory $f) => $f->opened()->expiringSoon()],

            // Finished
            [14, fn (GiftFactory $f) => $f->redeemed()],
            [3, fn (GiftFactory $f) => $f->claimedWithPin()->redeemed()],
            [4, fn (GiftFactory $f) => $f->expired()->redeemed()],                            // used before it ran out
            [6, fn (GiftFactory $f) => $f->expired(), true],                                        // never claimed
            [4, fn (GiftFactory $f) => $f->expired()->whatsappFailed()->smsFailed(), true],         // never reached the recipient
            [5, fn (GiftFactory $f) => $f->expired()->opened()],                              // received, never used
            [2, fn (GiftFactory $f) => $f->expired()->popupDismissed()],
        ];
    }

    /**
     * One gift per screen state for the test customer's app.
     *
     * @return list<callable(GiftFactory): GiftFactory>
     */
    protected function demoCustomerScenarios(): array
    {
        return [
            fn (GiftFactory $f) => $f->claimed(),             // home screen popup
            fn (GiftFactory $f) => $f->popupDismissed(),
            fn (GiftFactory $f) => $f->opened(),
            fn (GiftFactory $f) => $f->opened()->expiringSoon(),
            fn (GiftFactory $f) => $f->redeemed(),
            fn (GiftFactory $f) => $f->expired()->opened(),
        ];
    }

    /**
     * @param  callable(GiftFactory): GiftFactory  $state
     * @param  Collection<int, User>  $senders
     * @param  Collection<int, Product>  $products
     */
    protected function createGift(callable $state, Collection $senders, Collection $products, ?User $recipient = null): Gift
    {
        $sender = $senders->where('id', '!=', $recipient?->id)->random();
        $product = $products->random();

        $factory = Gift::factory()->state([
            'sender_id' => $sender->id,
            'product_id' => $product->id,
            'store_id' => $product->store_id,
        ]);

        if ($recipient) {
            $factory = $factory->forRecipient($recipient);
        }

        return $state($factory)->create();
    }

    /**
     * Online-gift products, else any products.
     *
     * @return Collection<int, Product>
     */
    protected function products(): Collection
    {
        $online = Product::query()->onlineGifts()->get();

        return $online->isNotEmpty() ? $online : Product::query()->inRandomOrder()->limit(30)->get();
    }

    protected function deleteDemoGifts(): int
    {
        // Gifts and order lines cascade
        return Order::query()->whereHas('gift')->where('payment_data->demo', true)->delete();
    }
}
