<?php

namespace App\Console\Commands;

use App\Services\TabbyService;
use Illuminate\Console\Command;

/**
 * One-off: tell Tabby where to send payment webhooks for this environment.
 */
class TabbyRegisterWebhook extends Command
{
    protected $signature = 'tabby:register-webhook {url? : Webhook URL (defaults to the api.v1.webhooks.tabby route)}';

    protected $description = 'Register the Tabby payment webhook URL with the configured secret header';

    public function handle(TabbyService $tabby): int
    {
        if (! $tabby->isConfigured()) {
            $this->error('Tabby is not configured: set TABBY_PUBLIC_KEY, TABBY_SECRET_KEY and TABBY_MERCHANT_CODE.');

            return self::FAILURE;
        }

        if (! config('services.tabby.webhook_secret')) {
            $this->error('Set TABBY_WEBHOOK_SECRET first (any long random string).');

            return self::FAILURE;
        }

        $url = $this->argument('url') ?: route('api.v1.webhooks.tabby');
        $result = $tabby->registerWebhook($url);

        $this->line(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! $result['success']) {
            $this->error("Tabby refused the webhook registration (HTTP {$result['status']}).");

            return self::FAILURE;
        }

        $this->info("Webhook registered: {$url}");

        return self::SUCCESS;
    }
}
