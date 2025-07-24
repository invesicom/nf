<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\AmazonProductController;
use App\Http\Controllers\SitemapController;

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
    return view('home');
})->name('home');

Route::get('/privacy', function () {
    return view('privacy');
});

// Newsletter routes
Route::prefix('newsletter')->name('newsletter.')->group(function () {
    Route::post('/subscribe', [NewsletterController::class, 'subscribe'])->name('subscribe');
    Route::post('/unsubscribe', [NewsletterController::class, 'unsubscribe'])->name('unsubscribe');
    Route::post('/check', [NewsletterController::class, 'checkSubscription'])->name('check');
    Route::get('/test-connection', [NewsletterController::class, 'testConnection'])->name('test');
});

// SEO and sitemap routes
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.main');
Route::get('/sitemap-index.xml', [SitemapController::class, 'sitemapIndex'])->name('sitemap.index');
Route::get('/sitemap-products-{page}.xml', [SitemapController::class, 'products'])
    ->name('sitemap.products')
    ->where('page', '[0-9]+');

// Dynamic robots.txt
Route::get('/robots.txt', function () {
    $robotsTxt = "User-agent: *\nAllow: /\n\n";
    $robotsTxt .= "# Allow all search engines to crawl everything\n";
    $robotsTxt .= "# No restrictions or disallowed paths\n\n";
    $robotsTxt .= "# Dynamic sitemap with all analyzed products\n";
    $robotsTxt .= "Sitemap: " . url('/sitemap.xml') . "\n\n";
    $robotsTxt .= "# Additional sitemap information\n";
    $robotsTxt .= "# - Main sitemap includes homepage, static pages, and recent products\n";
    $robotsTxt .= "# - Additional product sitemaps are generated automatically as needed\n";
    $robotsTxt .= "# - All analyzed Amazon products are included for better discoverability\n";
    
    return response($robotsTxt, 200, ['Content-Type' => 'text/plain']);
})->name('robots');

// Amazon product shareable routes
Route::get('/amazon/{asin}', [AmazonProductController::class, 'show'])
    ->name('amazon.product.show')
    ->where('asin', '[A-Z0-9]{10}');

Route::get('/amazon/{asin}/{slug}', [AmazonProductController::class, 'showWithSlug'])
    ->name('amazon.product.show.slug')
    ->where('asin', '[A-Z0-9]{10}')
    ->where('slug', '[a-z0-9-]+');
