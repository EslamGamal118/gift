<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\StoreOrderSeeder;
use Illuminate\Console\Command;

/**
 * Seed paid demo orders for one store so the merchant app has data to show.
 *
 *   php artisan orders:demo                       test store (966510000000), 24 orders
 *   php artisan orders:demo 966512345678 --count=40
 *   php artisan orders:demo 12 --fresh            replace store #12's earlier demo orders
 */
class SeedDemoStoreOrders extends Command
{
    protected $signature = 'orders:demo
        {store? : Store owner user id or phone (defaults to the test store)}
        {--count=24 : Number of orders to create}
        {--fresh : Delete this store\'s earlier demo orders first (real orders are never touched)}
        {--force : Allow running in production}';

    protected $description = 'Create paid demo orders in every status for a store';

    public function handle(StoreOrderSeeder $seeder): int
    {
        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to seed demo orders in production (use --force to override).');

            return self::FAILURE;
        }

        $count = (int) $this->option('count');

        if ($count < 1 || $count > 500) {
            $this->error('--count must be between 1 and 500.');

            return self::FAILURE;
        }

        $key = (string) ($this->argument('store') ?? StoreOrderSeeder::DEFAULT_STORE_PHONE);

        $store = User::query()
            ->ofType(User::TYPE_STORE)
            ->where(fn ($q) => ctype_digit($key) && strlen($key) < 9 ? $q->whereKey((int) $key) : $q->where('phone', $key))
            ->first();

        if (! $store) {
            $this->error("No store account matches \"{$key}\".");

            return self::FAILURE;
        }

        $seeder->store = $store;
        $seeder->count = $count;
        $seeder->fresh = (bool) $this->option('fresh');

        $seeder->setContainer($this->laravel)->setCommand($this)->__invoke();

        return self::SUCCESS;
    }
}
