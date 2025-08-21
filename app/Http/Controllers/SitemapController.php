<?php

namespace App\Http\Controllers;

use App\Models\AsinData;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Generate the main XML sitemap containing static pages and recent products.
     */
    public function index(): Response
    {
        $cacheKey = 'sitemap_main';
        $cacheDuration = 60 * 60; // 1 hour

        $sitemap = Cache::remember($cacheKey, $cacheDuration, function () {
            return $this->generateMainSitemap();
        });

        return response($sitemap, 200, [
            'Content-Type'  => 'application/xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Generate sitemap for analyzed products (paginated).
     */
    public function products(int $page = 1): Response
    {
        $limit = 1000; // Google recommends max 50k URLs per sitemap
        $cacheKey = "sitemap_products_page_{$page}";
        $cacheDuration = 60 * 30; // 30 minutes

        $sitemap = Cache::remember($cacheKey, $cacheDuration, function () use ($page, $limit) {
            return $this->generateProductSitemap($page, $limit);
        });

        return response($sitemap, 200, [
            'Content-Type'  => 'application/xml',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }

    /**
     * Generate sitemap index if we have multiple product sitemaps.
     */
    public function sitemapIndex(): Response
    {
        $cacheKey = 'sitemap_index';
        $cacheDuration = 60 * 60; // 1 hour

        $sitemapIndex = Cache::remember($cacheKey, $cacheDuration, function () {
            return $this->generateSitemapIndex();
        });

        return response($sitemapIndex, 200, [
            'Content-Type'  => 'application/xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Generate the main sitemap with static pages and featured products.
     */
    private function generateMainSitemap(): string
    {
        $urls = [];

        // Static pages
        $urls[] = [
            'loc'        => url('/'),
            'lastmod'    => '2024-12-19',
            'changefreq' => 'weekly',
            'priority'   => '1.0',
        ];

        $urls[] = [
            'loc'        => url('/privacy'),
            'lastmod'    => '2024-12-19',
            'changefreq' => 'monthly',
            'priority'   => '0.3',
        ];

        // Recent analyzed products (last 100 for main sitemap)
        $recentProducts = AsinData::all()
            ->filter(function ($product) {
                return $product->isAnalyzed();
            })
            ->sortByDesc('updated_at')
            ->take(100);

        foreach ($recentProducts as $product) {
            // Use SEO-friendly URL if available
            $seoUrl = $product->seo_url;

            $urls[] = [
                'loc'        => url($seoUrl),
                'lastmod'    => $product->updated_at->toISOString(),
                'changefreq' => 'monthly',
                'priority'   => $product->have_product_data ? '0.8' : '0.6',
            ];
        }

        return $this->buildXmlSitemap($urls);
    }

    /**
     * Generate product sitemap for a specific page.
     */
    private function generateProductSitemap(int $page, int $limit): string
    {
        $offset = ($page - 1) * $limit;

        // Get analyzed products for this page
        $allAnalyzedProducts = AsinData::all()
            ->filter(function ($product) {
                return $product->isAnalyzed();
            })
            ->sortByDesc('updated_at');

        $products = $allAnalyzedProducts->slice($offset, $limit);

        if ($products->isEmpty()) {
            // Return empty sitemap if no products on this page
            return $this->buildXmlSitemap([]);
        }

        $urls = [];
        foreach ($products as $product) {
            // Use SEO-friendly URL
            $seoUrl = $product->seo_url;

            // Higher priority for products with good grades and complete data
            $priority = $this->calculateProductPriority($product);

            $urls[] = [
                'loc'        => url($seoUrl),
                'lastmod'    => $product->updated_at->toISOString(),
                'changefreq' => 'monthly',
                'priority'   => $priority,
            ];
        }

        return $this->buildXmlSitemap($urls);
    }

    /**
     * Generate sitemap index if we have multiple sitemaps.
     */
    private function generateSitemapIndex(): string
    {
        $totalProducts = AsinData::all()
            ->filter(function ($product) {
                return $product->isAnalyzed();
            })
            ->count();

        $limit = 1000;
        $totalPages = ceil($totalProducts / $limit);

        // If we have less than 1000 products, just use main sitemap
        if ($totalPages <= 1) {
            $sitemaps = [
                [
                    'loc'     => url('/sitemap.xml'),
                    'lastmod' => now()->toISOString(),
                ],
            ];
        } else {
            // Create index with main sitemap + product sitemaps
            $sitemaps = [
                [
                    'loc'     => url('/sitemap.xml'),
                    'lastmod' => now()->toISOString(),
                ],
            ];

            for ($page = 1; $page <= $totalPages; $page++) {
                $sitemaps[] = [
                    'loc'     => url("/sitemap-products-{$page}.xml"),
                    'lastmod' => now()->toISOString(),
                ];
            }
        }

        return $this->buildXmlSitemapIndex($sitemaps);
    }

    /**
     * Calculate priority for a product based on analysis quality.
     */
    private function calculateProductPriority(AsinData $product): string
    {
        $priority = 0.5; // Base priority

        // Boost for complete product data
        if ($product->have_product_data) {
            $priority += 0.2;
        }

        // Boost for good grades
        if ($product->grade) {
            switch ($product->grade) {
                case 'A':
                    $priority += 0.2;
                    break;
                case 'B':
                    $priority += 0.1;
                    break;
                case 'C':
                    $priority += 0.05;
                    break;
            }
        }

        // Slight boost for products with low fake percentage (trustworthy)
        if ($product->fake_percentage !== null && $product->fake_percentage < 20) {
            $priority += 0.1;
        }

        // Cap at 0.9 (reserve 1.0 for homepage)
        return number_format(min($priority, 0.9), 1);
    }

    /**
     * Build XML sitemap from URLs array.
     */
    private function buildXmlSitemap(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.PHP_EOL;

        foreach ($urls as $url) {
            $xml .= '    <url>'.PHP_EOL;
            $xml .= '        <loc>'.htmlspecialchars($url['loc']).'</loc>'.PHP_EOL;
            $xml .= '        <lastmod>'.$url['lastmod'].'</lastmod>'.PHP_EOL;
            $xml .= '        <changefreq>'.$url['changefreq'].'</changefreq>'.PHP_EOL;
            $xml .= '        <priority>'.$url['priority'].'</priority>'.PHP_EOL;
            $xml .= '    </url>'.PHP_EOL;
        }

        $xml .= '</urlset>'.PHP_EOL;

        return $xml;
    }

    /**
     * Build XML sitemap index from sitemaps array.
     */
    private function buildXmlSitemapIndex(array $sitemaps): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL;
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.PHP_EOL;

        foreach ($sitemaps as $sitemap) {
            $xml .= '    <sitemap>'.PHP_EOL;
            $xml .= '        <loc>'.htmlspecialchars($sitemap['loc']).'</loc>'.PHP_EOL;
            $xml .= '        <lastmod>'.$sitemap['lastmod'].'</lastmod>'.PHP_EOL;
            $xml .= '    </sitemap>'.PHP_EOL;
        }

        $xml .= '</sitemapindex>'.PHP_EOL;

        return $xml;
    }

    /**
     * Clear sitemap cache when products are updated.
     */
    public static function clearCache(): void
    {
        Cache::forget('sitemap_main');
        Cache::forget('sitemap_index');

        // Clear product sitemap pages (up to 100 pages should be enough)
        for ($page = 1; $page <= 100; $page++) {
            Cache::forget("sitemap_products_page_{$page}");
        }
    }
}
