<?php

use App\Http\Controllers\AmazonProductController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    $seoService = app(\App\Services\SEOService::class);
    $seoData = $seoService->generateHomeSEOData();

    return view('home', compact('seoData'));
})->name('home');

Route::get('/privacy', function () {
    return view('privacy');
});

// Information pages for LLM discoverability
Route::get('/free-amazon-fake-review-checker', function () {
    return view('free-checker');
})->name('free-checker');

Route::get('/fakespot-alternative', function () {
    return view('fakespot-alternative');
})->name('fakespot-alternative');

Route::get('/how-it-works', function () {
    return view('how-it-works');
})->name('how-it-works');

Route::get('/faq', function () {
    return view('faq');
})->name('faq');

// Contact routes
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');
Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('throttle:5,1') // 5 attempts per minute
    ->name('contact.submit');

Route::get('/products', [AmazonProductController::class, 'index'])->name('products.index');

// Newsletter routes
Route::prefix('newsletter')->name('newsletter.')->group(function () {
    Route::post('/subscribe', [NewsletterController::class, 'subscribe'])->name('subscribe');
    Route::post('/unsubscribe', [NewsletterController::class, 'unsubscribe'])->name('unsubscribe');
    Route::post('/check', [NewsletterController::class, 'checkSubscription'])->name('check');
    Route::get('/test-connection', [NewsletterController::class, 'testConnection'])->name('test');
});

// SEO and sitemap routes (without session/cookie middleware for proper caching)
Route::get('/sitemap.xml', [SitemapController::class, 'index'])
    ->withoutMiddleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('sitemap.main');
Route::get('/sitemap-static.xml', [SitemapController::class, 'static'])
    ->withoutMiddleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('sitemap.static');
Route::get('/sitemap-products.xml', [SitemapController::class, 'products'])
    ->withoutMiddleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('sitemap.products');
Route::get('/sitemap-analysis.xml', [SitemapController::class, 'analysis'])
    ->withoutMiddleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('sitemap.analysis');

// Dynamic robots.txt
Route::get('/robots.txt', function () {
    $robotsTxt = "User-agent: *\nAllow: /\n\n";
    $robotsTxt .= "# Allow all search engines to crawl everything\n";
    $robotsTxt .= "# No restrictions or disallowed paths\n\n";
    $robotsTxt .= "# Dynamic sitemap with all analyzed products\n";
    $robotsTxt .= 'Sitemap: '.url('/sitemap.xml')."\n\n";
    $robotsTxt .= "# Additional sitemap information\n";
    $robotsTxt .= "# - Main sitemap includes homepage, static pages, and recent products\n";
    $robotsTxt .= "# - Additional product sitemaps are generated automatically as needed\n";
    $robotsTxt .= "# - All analyzed Amazon products are included for better discoverability\n";

    return response($robotsTxt, 200, ['Content-Type' => 'text/plain']);
})->name('robots');

// Amazon product shareable routes with country
Route::get('/amazon/{country}/{asin}', [AmazonProductController::class, 'show'])
    ->name('amazon.product.show')
    ->where('country', '[a-z]{2}')
    ->where('asin', '[A-Z0-9]{10}');

Route::get('/amazon/{country}/{asin}/{slug}', [AmazonProductController::class, 'showWithSlug'])
    ->name('amazon.product.show.slug')
    ->where('country', '[a-z]{2}')
    ->where('asin', '[A-Z0-9]{10}')
    ->where('slug', '[a-z0-9-]+');

// Legacy routes (backward compatibility) - redirect to country-specific URLs
Route::get('/amazon/{asin}', [AmazonProductController::class, 'showLegacy'])
    ->name('amazon.product.show.legacy')
    ->where('asin', '[A-Z0-9]{10}');

Route::get('/amazon/{asin}/{slug}', [AmazonProductController::class, 'showWithSlugLegacy'])
    ->name('amazon.product.show.slug.legacy')
    ->where('asin', '[A-Z0-9]{10}')
    ->where('slug', '[a-z0-9-]+');
