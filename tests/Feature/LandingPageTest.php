<?php

namespace Tests\Feature;

use App\Http\Middleware\SetWebLocale;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_it_is_arabic_and_rtl_by_default(): void
    {
        $response = $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee(__('landing.hero.title', [], 'ar'));

        $this->assertActiveLanguage('ar', $response->getContent());
    }

    public function test_the_switcher_renders_english_ltr_and_remembers_it(): void
    {
        $response = $this->get('/?lang=en')
            ->assertOk()
            ->assertSessionHas(SetWebLocale::SESSION_KEY, 'en')
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('Everything you need for your gift, in one place')
            ->assertSee('Download the app')
            ->assertDontSee(__('landing.hero.title', [], 'ar'));

        $this->assertActiveLanguage('en', $response->getContent());

        // Next visit without ?lang keeps English
        $this->get('/')->assertSee('<html lang="en" dir="ltr">', false);

        // Switching back
        $this->get('/?lang=ar')->assertSee('<html lang="ar" dir="rtl">', false);
    }

    public function test_an_unsupported_language_falls_back_to_arabic(): void
    {
        $this->get('/?lang=fr')
            ->assertOk()
            ->assertSessionMissing(SetWebLocale::SESSION_KEY)
            ->assertSee('<html lang="ar" dir="rtl">', false);
    }

    public function test_every_landing_key_exists_in_both_languages(): void
    {
        // dotted key => 'text', or the item count for lists
        $keys = function (array $lines, string $prefix = '') use (&$keys) {
            return collect($lines)->flatMap(
                fn ($value, $key) => is_array($value) && ! array_is_list($value)
                    ? $keys($value, "{$prefix}{$key}.")
                    : ["{$prefix}{$key}" => is_array($value) ? count($value) : 'text']
            );
        };

        $this->assertEquals(
            $keys(require lang_path('ar/landing.php'))->all(),
            $keys(require lang_path('en/landing.php'))->all(),
        );
    }

    /**
     * Exactly one switcher option is active, and it is the given language.
     */
    protected function assertActiveLanguage(string $locale, string $html): void
    {
        preg_match_all('/class="lang-switch__option is-active"\s+lang="(\w+)"[^>]*aria-current="true"/', $html, $matches);

        $this->assertSame([$locale], $matches[1]);
    }
}
