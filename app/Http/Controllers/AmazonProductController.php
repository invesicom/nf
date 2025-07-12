<?php

namespace App\Http\Controllers;

use App\Models\AsinData;
use App\Services\LoggingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AmazonProductController extends Controller
{
    /**
     * Display the Amazon product page.
     */
    public function show(string $asin, Request $request): View
    {
        LoggingService::log('Displaying Amazon product page', [
            'asin' => $asin,
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        // Find the product data in the database
        $asinData = AsinData::where('asin', $asin)
            ->where('country', 'us')
            ->first();

        if (!$asinData) {
            LoggingService::log('Product not found in database', [
                'asin' => $asin,
            ]);
            
            // Redirect to home page with error
            return view('amazon.product-not-found', [
                'asin' => $asin,
                'amazon_url' => "https://www.amazon.com/dp/{$asin}",
            ]);
        }

        // Check if the product has been fully analyzed
        if (!$asinData->isAnalyzed()) {
            LoggingService::log('Product not yet analyzed', [
                'asin' => $asin,
                'has_reviews' => !empty($asinData->getReviewsArray()),
                'has_openai_result' => !empty($asinData->openai_result),
            ]);
            
            return view('amazon.product-analyzing', [
                'asinData' => $asinData,
                'amazon_url' => "https://www.amazon.com/dp/{$asin}",
            ]);
        }

        LoggingService::log('Displaying analyzed product', [
            'asin' => $asin,
            'has_product_data' => $asinData->have_product_data,
            'product_title' => $asinData->product_title ?? 'N/A',
            'fake_percentage' => $asinData->fake_percentage,
            'grade' => $asinData->grade,
        ]);

        // Display the full product analysis
        return view('amazon.product-show', [
            'asinData' => $asinData,
            'amazon_url' => "https://www.amazon.com/dp/{$asin}",
            'meta_title' => $this->generateMetaTitle($asinData),
            'meta_description' => $this->generateMetaDescription($asinData),
        ]);
    }

    /**
     * Generate SEO-friendly meta title.
     */
    private function generateMetaTitle(AsinData $asinData): string
    {
        $title = $asinData->product_title ?? 'Amazon Product';
        $grade = $asinData->grade ?? 'N/A';
        $fakePercentage = $asinData->fake_percentage ?? 0;
        
        return "{$title} - Review Analysis (Grade: {$grade}, {$fakePercentage}% Fake) | Null Fake";
    }

    /**
     * Generate SEO-friendly meta description.
     */
    private function generateMetaDescription(AsinData $asinData): string
    {
        $title = $asinData->product_title ?? 'Amazon Product';
        $grade = $asinData->grade ?? 'N/A';
        $fakePercentage = $asinData->fake_percentage ?? 0;
        $totalReviews = count($asinData->getReviewsArray());
        
        $description = "AI analysis of {$totalReviews} reviews for {$title}. ";
        $description .= "Grade: {$grade} ({$fakePercentage}% fake reviews detected). ";
        $description .= "Get authentic review insights and adjusted ratings on Null Fake.";
        
        return $description;
    }
}
