<?php

namespace App\Http\Controllers;

use App\Models\AsinData;
use App\Services\LoggingService;
use App\Services\SEOService;
use Illuminate\Http\Request;

class AmazonProductController extends Controller
{
    /**
     * Display the Amazon product page (without slug).
     */
    public function show(string $country, string $asin, Request $request)
    {
        LoggingService::log('Displaying Amazon product page', [
            'country'    => $country,
            'asin'       => $asin,
            'user_agent' => $request->userAgent(),
            'ip'         => $request->ip(),
        ]);

        // Find the product data in the database for the specific country
        $asinData = AsinData::where('asin', $asin)
            ->where('country', $country)
            ->first();

        if (!$asinData) {
            LoggingService::log('Product not found in database', [
                'country' => $country,
                'asin'    => $asin,
            ]);

            return view('amazon.product-not-found', [
                'asin'       => $asin,
                'amazon_url' => $this->buildAmazonUrl($asin, $country),
            ]);
        }

        // Check if the product has been fully analyzed
        if (!$asinData->isAnalyzed()) {
            LoggingService::log('Product not yet analyzed', [
                'asin'              => $asin,
                'has_reviews'       => !empty($asinData->getReviewsArray()),
                'has_openai_result' => !empty($asinData->openai_result),
            ]);

            return view('amazon.product-not-found', [
                'asin'       => $asin,
                'amazon_url' => "https://www.amazon.com/dp/{$asin}",
                'message'    => 'This product analysis is still in progress. Please try again in a few moments.',
            ]);
        }

        // If product has a title/slug, redirect to SEO-friendly URL
        if ($asinData->have_product_data && $asinData->slug) {
            LoggingService::log('Redirecting to SEO-friendly URL', [
                'country' => $country,
                'asin'    => $asin,
                'slug'    => $asinData->slug,
            ]);

            return redirect()->route('amazon.product.show.slug', [
                'country' => $country,
                'asin'    => $asin,
                'slug'    => $asinData->slug,
            ], 301);
        }

        return $this->renderProductPage($asinData);
    }

    /**
     * Display the Amazon product page with slug (SEO-friendly URL).
     */
    public function showWithSlug(string $country, string $asin, string $slug, Request $request)
    {
        LoggingService::log('Displaying Amazon product page with slug', [
            'country'    => $country,
            'asin'       => $asin,
            'slug'       => $slug,
            'user_agent' => $request->userAgent(),
            'ip'         => $request->ip(),
        ]);

        // Find the product data in the database for the specific country
        $asinData = AsinData::where('asin', $asin)
            ->where('country', $country)
            ->first();

        if (!$asinData) {
            LoggingService::log('Product not found in database', [
                'country' => $country,
                'asin'    => $asin,
                'slug'    => $slug,
            ]);

            return view('amazon.product-not-found', [
                'asin'       => $asin,
                'amazon_url' => $this->buildAmazonUrl($asin, $country),
            ]);
        }

        // Check if the product has been fully analyzed
        if (!$asinData->isAnalyzed()) {
            LoggingService::log('Product not yet analyzed', [
                'asin'              => $asin,
                'slug'              => $slug,
                'has_reviews'       => !empty($asinData->getReviewsArray()),
                'has_openai_result' => !empty($asinData->openai_result),
            ]);

            return view('amazon.product-not-found', [
                'asin'       => $asin,
                'amazon_url' => "https://www.amazon.com/dp/{$asin}",
                'message'    => 'This product analysis is still in progress. Please try again in a few moments.',
            ]);
        }

        // Verify the slug matches the current product title
        if ($asinData->have_product_data && $asinData->slug && $asinData->slug !== $slug) {
            LoggingService::log('Slug mismatch, redirecting to correct slug', [
                'asin'          => $asin,
                'provided_slug' => $slug,
                'correct_slug'  => $asinData->slug,
            ]);

            return redirect()->route('amazon.product.show.slug', [
                'country' => $country,
                'asin' => $asin,
                'slug' => $asinData->slug,
            ], 301);
        }

        return $this->renderProductPage($asinData);
    }

    /**
     * Render the product page view.
     */
    private function renderProductPage(AsinData $asinData)
    {
        LoggingService::log('Rendering analyzed product page', [
            'asin'             => $asinData->asin,
            'has_product_data' => $asinData->have_product_data,
            'product_title'    => $asinData->product_title ?? 'N/A',
            'fake_percentage'  => $asinData->fake_percentage,
            'grade'            => $asinData->grade,
        ]);

        // Generate comprehensive SEO data using the new SEOService
        $seoService = app(SEOService::class);
        $seoData = $seoService->generateProductSEOData($asinData);

        // Display the full product analysis
        return response()
            ->view('amazon.product-show', [
                'asinData'         => $asinData,
                'amazon_url'       => $this->buildAmazonUrl($asinData->asin, $asinData->country ?? 'us'),
                'meta_title'       => $seoData['meta_title'],
                'meta_description' => $seoData['meta_description'],
                'canonical_url'    => $seoData['canonical_url'],
                'seo_data'         => $seoData,
            ])
            ->header('Cache-Control', 'public, max-age=900') // 15 minutes cache
            ->header('Vary', 'Accept-Encoding'); // Handle compression variations
    }

    /**
     * Legacy method: Display Amazon product page without country (redirect to country-specific URL).
     */
    public function showLegacy(string $asin, Request $request)
    {
        LoggingService::log('Legacy URL accessed - redirecting to country-specific URL', [
            'asin'       => $asin,
            'user_agent' => $request->userAgent(),
        ]);

        // Find the product in database to determine country
        $asinData = AsinData::where('asin', $asin)->first();

        if (!$asinData) {
            // Product not found - show not found page with US as default
            return view('amazon.product-not-found', [
                'asin'       => $asin,
                'amazon_url' => $this->buildAmazonUrl($asin, 'us'),
            ]);
        }

        // Redirect to country-specific URL
        return redirect()->route('amazon.product.show', [
            'country' => $asinData->country,
            'asin'    => $asin,
        ], 301);
    }

    /**
     * Legacy method: Display Amazon product page with slug but without country.
     */
    public function showWithSlugLegacy(string $asin, string $slug, Request $request)
    {
        LoggingService::log('Legacy slug URL accessed - redirecting to country-specific URL', [
            'asin'       => $asin,
            'slug'       => $slug,
            'user_agent' => $request->userAgent(),
        ]);

        // Find the product in database to determine country
        $asinData = AsinData::where('asin', $asin)->first();

        if (!$asinData) {
            // Product not found - show not found page
            return view('amazon.product-not-found', [
                'asin'       => $asin,
                'amazon_url' => $this->buildAmazonUrl($asin, 'us'),
            ]);
        }

        // Redirect to country-specific URL with slug
        return redirect()->route('amazon.product.show.slug', [
            'country' => $asinData->country,
            'asin'    => $asin,
            'slug'    => $slug,
        ], 301);
    }

    /**
     * Build Amazon URL with country-specific domain and affiliate tag if configured.
     */
    private function buildAmazonUrl(string $asin, string $country = 'us'): string
    {
        // Map countries to Amazon domains
        $domains = [
            'us' => 'amazon.com',
            'gb' => 'amazon.co.uk',
            'ca' => 'amazon.ca',
            'de' => 'amazon.de',
            'fr' => 'amazon.fr',
            'it' => 'amazon.it',
            'es' => 'amazon.es',
            'jp' => 'amazon.co.jp',
            'au' => 'amazon.com.au',
            'mx' => 'amazon.com.mx',
            'in' => 'amazon.in',
            'sg' => 'amazon.sg',
            'br' => 'amazon.com.br',
            'nl' => 'amazon.nl',
            'tr' => 'amazon.com.tr',
            'ae' => 'amazon.ae',
            'sa' => 'amazon.sa',
            'se' => 'amazon.se',
            'pl' => 'amazon.pl',
            'eg' => 'amazon.eg',
            'be' => 'amazon.be',
        ];

        $domain = $domains[$country] ?? $domains['us'];
        $url = "https://www.{$domain}/dp/{$asin}";

        // Get country-specific affiliate tag
        $affiliateTag = $this->getAffiliateTagForCountry($country);
        if ($affiliateTag) {
            $url .= "?tag={$affiliateTag}";
        }

        return $url;
    }

    /**
     * Get affiliate tag for specific country.
     */
    private function getAffiliateTagForCountry(string $country): ?string
    {
        // Check if affiliate links are enabled
        if (!config('amazon.affiliate.enabled', true)) {
            return null;
        }

        // Try country-specific affiliate tag from new config system
        $countryTag = config("amazon.affiliate.tags.{$country}");
        if ($countryTag) {
            return $countryTag;
        }

        // Fall back to old config system for backward compatibility
        $legacyCountryTag = config("app.amazon_affiliate_tag_{$country}");
        if ($legacyCountryTag) {
            return $legacyCountryTag;
        }

        // Fall back to default affiliate tag (usually for US)
        $defaultTag = config('app.amazon_affiliate_tag');

        // Only use default tag for US, as it won't work on other domains
        return ($country === 'us') ? $defaultTag : null;
    }

    /**
     * Generate SEO-friendly meta title focusing on unique analysis value.
     */
    private function generateMetaTitle(AsinData $asinData): string
    {
        $title = $asinData->product_title ?? 'Amazon Product';
        $grade = $asinData->grade ?? 'N/A';
        $fakePercentage = $asinData->fake_percentage ?? 0;
        $adjustedRating = $asinData->adjusted_rating ?? 0;

        // Limit title to ~60 characters for SEO
        $shortTitle = \Str::limit($title, 25, '');

        if ($fakePercentage > 30) {
            // High fake percentage - emphasize warning
            return "⚠️ {$shortTitle} - {$fakePercentage}% FAKE Reviews Detected | Grade {$grade}";
        } elseif ($fakePercentage > 10) {
            // Moderate fake percentage - emphasize analysis
            return "{$shortTitle} Review Analysis - {$fakePercentage}% Fake | Grade {$grade} | Null Fake";
        } else {
            // Low fake percentage - emphasize trustworthiness
            return "✅ {$shortTitle} - Verified Reviews | Grade {$grade} | {$adjustedRating}★ Rating";
        }
    }

    /**
     * Generate SEO-friendly meta description highlighting unique insights.
     */
    private function generateMetaDescription(AsinData $asinData): string
    {
        $title = $asinData->product_title ?? 'Amazon Product';
        $grade = $asinData->grade ?? 'N/A';
        $fakePercentage = $asinData->fake_percentage ?? 0;
        $adjustedRating = $asinData->adjusted_rating ?? 0;
        $amazonRating = $asinData->amazon_rating ?? 0;
        $totalReviews = count($asinData->getReviewsArray());

        $ratingDifference = round($amazonRating - $adjustedRating, 1);

        $description = "AI-powered fake review analysis of {$totalReviews} reviews for {$title}. ";

        if ($fakePercentage > 30) {
            $description .= "⚠️ HIGH RISK: {$fakePercentage}% fake reviews detected (Grade {$grade}). ";
            $description .= "Adjusted rating: {$adjustedRating}★ vs Amazon's {$amazonRating}★. ";
            $description .= 'Avoid this product - find better alternatives on Null Fake.';
        } elseif ($fakePercentage > 10) {
            $description .= "CAUTION: {$fakePercentage}% fake reviews found (Grade {$grade}). ";
            $description .= "True rating: {$adjustedRating}★ (Amazon shows {$amazonRating}★). ";
            if ($ratingDifference > 0.5) {
                $description .= "Rating inflated by {$ratingDifference} stars due to fake reviews. ";
            }
            $description .= 'Get the real story behind the reviews.';
        } else {
            $description .= "✅ TRUSTWORTHY: Only {$fakePercentage}% fake reviews (Grade {$grade}). ";
            $description .= "Genuine rating: {$adjustedRating}★. ";
            $description .= 'This product has authentic reviews you can trust. ';
            $description .= "See detailed analysis and why it earned an {$grade} grade.";
        }

        return $description;
    }

    /**
     * Generate additional SEO data for enhanced meta tags.
     */
    private function generateSeoData(AsinData $asinData): array
    {
        $fakePercentage = $asinData->fake_percentage ?? 0;
        $grade = $asinData->grade ?? 'N/A';
        $adjustedRating = $asinData->adjusted_rating ?? 0;
        $totalReviews = count($asinData->getReviewsArray());

        // Generate keywords based on analysis
        $keywords = [
            'fake review detector',
            'Amazon review analysis',
            'review authenticity',
            'fake review checker',
            $asinData->asin,
        ];

        if ($asinData->product_title) {
            $keywords[] = $asinData->product_title;
        }

        if ($fakePercentage > 30) {
            $keywords = array_merge($keywords, ['fake reviews', 'review fraud', 'avoid product']);
        } elseif ($fakePercentage > 10) {
            $keywords = array_merge($keywords, ['review manipulation', 'inflated rating']);
        } else {
            $keywords = array_merge($keywords, ['genuine reviews', 'trustworthy product', 'verified reviews']);
        }

        // Generate social media title (shorter)
        $socialTitle = $asinData->product_title ?
            \Str::limit($asinData->product_title, 40)." - Grade {$grade}" :
            "Amazon Product Analysis - Grade {$grade}";

        // Generate social media description
        $socialDescription = $fakePercentage > 30 ?
            "⚠️ {$fakePercentage}% fake reviews detected! See the real rating: {$adjustedRating}★" :
            "✅ Analysis complete: {$fakePercentage}% fake reviews, Grade {$grade}, {$adjustedRating}★ rating";

        return [
            'keywords'           => implode(', ', $keywords),
            'social_title'       => $socialTitle,
            'social_description' => $socialDescription,
            'review_summary'     => $this->generateReviewSummary($asinData),
            'trust_score'        => $this->calculateTrustScore($asinData),
        ];
    }

    /**
     * Generate a concise review summary for SEO.
     */
    private function generateReviewSummary(AsinData $asinData): string
    {
        $fakePercentage = $asinData->fake_percentage ?? 0;
        $grade = $asinData->grade ?? 'N/A';
        $totalReviews = count($asinData->getReviewsArray());

        if ($fakePercentage > 50) {
            return "Extremely high fake review activity detected in {$totalReviews} reviews analyzed";
        } elseif ($fakePercentage > 30) {
            return 'High fake review percentage found - exercise caution before purchasing';
        } elseif ($fakePercentage > 10) {
            return 'Moderate fake review activity detected - some rating inflation present';
        } else {
            return 'Low fake review activity - product appears to have genuine customer feedback';
        }
    }

    /**
     * Calculate a trust score for the product.
     */
    private function calculateTrustScore(AsinData $asinData): int
    {
        $fakePercentage = $asinData->fake_percentage ?? 0;
        $totalReviews = count($asinData->getReviewsArray());

        // Base score from fake percentage (inverted)
        $baseScore = max(0, 100 - $fakePercentage);

        // Adjust for number of reviews (more reviews = more reliable)
        if ($totalReviews >= 100) {
            $baseScore += 10;
        } elseif ($totalReviews >= 50) {
            $baseScore += 5;
        } elseif ($totalReviews < 10) {
            $baseScore -= 10;
        }

        return max(0, min(100, round($baseScore)));
    }

    /**
     * Display a paginated list of all analyzed products.
     */
    public function index(Request $request)
    {
        LoggingService::log('Displaying products listing page', [
            'page'       => $request->get('page', 1),
            'user_agent' => $request->userAgent(),
            'ip'         => $request->ip(),
        ]);

        // Get analyzed products using centralized policy for consistent filtering
        $policy = app(\App\Services\ProductAnalysisPolicy::class);
        $query = AsinData::query();
        $products = $policy->applyDisplayableConstraints($query)
            ->orderBy('first_analyzed_at', 'desc')
            ->paginate(50);

        // Log query results for debugging
        LoggingService::log('Products query results', [
            'total_asin_data'   => AsinData::count(),
            'analyzed_products' => $products->total(),
            'displayed_on_page' => $products->count(),
            'current_page'      => $products->currentPage(),
        ]);

        return response()
            ->view('products.index', [
                'products' => $products,
            ])
            ->header('Cache-Control', 'public, max-age=300') // 5 minutes cache
            ->header('Vary', 'Accept-Encoding'); // Handle compression variations
    }
}
