@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
    $languages = ['ar' => 'عربي', 'en' => 'Eng'];

    // Figma hero background; falls back to the old banner until the new file is uploaded
    $heroImage = file_exists(public_path('images/hero_banner/remove text and buttons from it.png'))
        ? 'images/hero_banner/'.rawurlencode('remove text and buttons from it.png')
        : 'images/hero_banner.png';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('landing.meta.title') }}</title>
    <meta name="description" content="{{ __('landing.meta.description') }}">
    @foreach (array_keys($languages) as $code)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ route('landing', ['lang' => $code]) }}">
    @endforeach

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600&family=IBM+Plex+Sans:wght@400;600&display=swap" rel="stylesheet">

    <link rel="preload" as="image" href="{{ asset($heroImage) }}" fetchpriority="high">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
</head>
<body>
    {{-- ============================== Hero ============================== --}}
    <section class="hero" id="home">
        <img class="hero__bg" src="{{ asset($heroImage) }}" alt="" width="1440" height="1024" fetchpriority="high" decoding="async">

        <header class="hero__nav">
            <a href="#home" class="hero__brand" aria-label="{{ __('landing.brand.home_label') }}">
                <img src="{{ asset('images/logo/'.rawurlencode('تهادوا لوجو-01 2.png')) }}" alt="{{ __('landing.brand.name') }}" width="97" height="80">
            </a>

            <button class="hero__toggle" type="button" aria-controls="hero-menu" aria-expanded="false"
                    aria-label="{{ __('landing.nav.open') }}"
                    data-label-open="{{ __('landing.nav.open') }}" data-label-close="{{ __('landing.nav.close') }}">
                <span></span><span></span><span></span>
            </button>

            <nav class="hero__menu" id="hero-menu" aria-label="{{ __('landing.nav.label') }}">
                <ul class="hero__links">
                    <li><a href="#home" class="is-active" aria-current="page">{{ __('landing.nav.home') }}</a></li>
                    <li><a href="#about">{{ __('landing.nav.about') }}</a></li>
                    <li><a href="#services">{{ __('landing.nav.services') }}</a></li>
                    <li><a href="#providers">{{ __('landing.nav.providers') }}</a></li>
                    <li><a href="#testimonials">{{ __('landing.nav.testimonials') }}</a></li>
                    <li><a href="#contact">{{ __('landing.nav.contact') }}</a></li>
                </ul>

                <div class="hero__aside">
                    {{-- Figma "filter selector": 134 × 40, two 63 × 32 segments --}}
                    <div class="lang-switch" role="group" aria-label="{{ __('landing.language.label') }}">
                        @foreach ($languages as $code => $label)
                            <a href="{{ route('landing', ['lang' => $code]) }}" @class(['lang-switch__option', 'is-active' => $code === $locale])
                               lang="{{ $code }}" hreflang="{{ $code }}" @if ($code === $locale) aria-current="true" @endif>{{ $label }}</a>
                        @endforeach
                    </div>
                    <img class="hero__vision" src="{{ asset('images/logo/vision-2030.png') }}" alt="{{ __('landing.brand.vision_alt') }}" width="112" height="92">
                </div>
            </nav>
        </header>

        <div class="hero__content">
            <div class="hero__copy">
                <h1 class="hero__title">{{ __('landing.hero.title') }}</h1>
                <p class="hero__subtitle">{{ __('landing.hero.subtitle') }}</p>
                <a href="#download" class="hero__cta">{{ __('landing.hero.cta') }}</a>
            </div>
        </div>
    </section>

    <main class="intro">
        {{-- =========================== About us =========================== --}}
        {{-- Text at the start (right in Arabic), image at the end --}}
        <section class="feature" id="about" aria-labelledby="about-title">
            <div class="feature__body">
                <h2 class="feature__title" id="about-title">{{ __('landing.about.title') }}</h2>
                <p class="feature__text">{{ __('landing.about.text') }}</p>
            </div>
            <figure class="feature__media">
                <img src="{{ asset('images/about_section_banner.jpg') }}" alt="{{ __('landing.about.image_alt') }}" width="1280" height="720" loading="lazy" decoding="async">
            </figure>
        </section>

        {{-- ============================ Goals ============================ --}}
        {{-- Mirrored: image at the start, list at the end --}}
        <section class="feature" id="goals" aria-labelledby="goals-title">
            <figure class="feature__media">
                <img src="{{ asset('images/goals_section_image.jpg') }}" alt="{{ __('landing.goals.image_alt') }}" width="1280" height="720" loading="lazy" decoding="async">
            </figure>
            <div class="feature__body">
                <h2 class="feature__title" id="goals-title">{{ __('landing.goals.title') }}</h2>
                <ol class="feature__list feature__list--numbered">
                    @foreach (__('landing.goals.items') as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- ======================= Vision & mission ======================= --}}
        {{-- Text at the start, image at the end --}}
        <section class="feature" id="vision" aria-labelledby="vision-title">
            <div class="feature__body">
                <h2 class="feature__title" id="vision-title">{{ __('landing.vision.title') }}</h2>
                <p class="feature__text">{{ __('landing.vision.intro') }}</p>
                <ul class="feature__list feature__list--bulleted">
                    @foreach (__('landing.vision.items') as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
            <figure class="feature__media">
                <img src="{{ asset('images/vision_box_item.jpg') }}" alt="{{ __('landing.vision.image_alt') }}" width="1280" height="720" loading="lazy" decoding="async">
            </figure>
        </section>

        {{-- ======================== How we serve you ======================== --}}
        <section class="services" id="services" aria-labelledby="services-title">
            <header class="services__header">
                <h2 class="services__title" id="services-title">{{ __('landing.services.title') }}</h2>
                <p class="services__subtitle">{{ __('landing.services.subtitle') }}</p>
            </header>

            {{-- 3 × 424px cards, 24px apart (1320px); the first card sits at the start (right in Arabic) --}}
            <ul class="services__list">
                @foreach (['shopping' => 'gift-box', 'special' => 'shopping-bag', 'delivery' => 'delivery-truck'] as $key => $icon)
                    <li class="service-card">
                        <img class="service-card__icon" src="{{ asset("images/services/{$icon}.png") }}" alt="" width="100" height="100" loading="lazy" decoding="async">
                        <h3 class="service-card__title">{{ __("landing.services.items.{$key}.title") }}</h3>
                        <p class="service-card__text">{{ __("landing.services.items.{$key}.text") }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    </main>

    <script>
        (function () {
            var toggle = document.querySelector('.hero__toggle');
            var nav = document.querySelector('.hero__nav');

            toggle.addEventListener('click', function () {
                var open = nav.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', open);
                toggle.setAttribute('aria-label', open ? toggle.dataset.labelClose : toggle.dataset.labelOpen);
            });
        })();
    </script>
</body>
</html>
