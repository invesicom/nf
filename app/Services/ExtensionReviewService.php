<?php

namespace App\Services;

use App\Models\AsinData;
use App\Services\LoggingService;

class ExtensionReviewService
{
    /**
     * Process review data from Chrome extension.
     */
    public function processExtensionData(array $extensionData): AsinData
    {
        $asin = $extensionData['asin'];
        $country = $extensionData['country'];

        LoggingService::log('Processing Chrome extension data', [
            'asin' => $asin,
            'country' => $country,
            'total_reviews' => $extensionData['total_reviews'],
            'extracted_reviews' => count($extensionData['reviews']),
            'extension_version' => $extensionData['extension_version'],
        ]);

        // Transform extension review format to internal format
        $transformedReviews = $this->transformReviewsFormat($extensionData['reviews']);

        // Extract product information from URL if possible
        $productInfo = $this->extractProductInfoFromUrl($extensionData['product_url']);

        // Create or update AsinData record
        $asinData = AsinData::updateOrCreate(
            [
                'asin' => $asin,
                'country' => $country,
            ],
            [
                'product_title' => $productInfo['title'] ?? null,
                'product_description' => $productInfo['description'] ?? '',
                'product_image_url' => $productInfo['image_url'] ?? null,
                'reviews' => json_encode($transformedReviews),
                'total_reviews_on_amazon' => $extensionData['total_reviews'],
                'status' => 'fetched',
                'have_product_data' => !empty($productInfo['title']),
                'source' => 'chrome_extension',
                'extension_version' => $extensionData['extension_version'],
                'extraction_timestamp' => $extensionData['extraction_timestamp'],
            ]
        );

        LoggingService::log('Chrome extension data processed successfully', [
            'asin' => $asin,
            'asin_data_id' => $asinData->id,
            'review_count' => count($transformedReviews),
        ]);

        return $asinData;
    }

    /**
     * Transform Chrome extension review format to internal format.
     */
    private function transformReviewsFormat(array $extensionReviews): array
    {
        $transformedReviews = [];

        foreach ($extensionReviews as $review) {
            $transformedReviews[] = [
                'id' => $review['review_id'],
                'author' => $review['author'],
                'title' => $review['title'],
                'content' => $review['content'],
                'rating' => $review['rating'],
                'date' => $review['date'],
                'verified_purchase' => $review['verified_purchase'],
                'vine_customer' => $review['vine_customer'],
                'helpful_votes' => $review['helpful_votes'],
                'extraction_index' => $review['extraction_index'],
            ];
        }

        LoggingService::log('Transformed extension reviews', [
            'original_count' => count($extensionReviews),
            'transformed_count' => count($transformedReviews),
        ]);

        return $transformedReviews;
    }

    /**
     * Extract basic product information from Amazon URL.
     */
    private function extractProductInfoFromUrl(string $productUrl): array
    {
        $productInfo = [
            'title' => null,
            'description' => '',
            'image_url' => null,
        ];

        // Try to extract product title from URL path
        if (preg_match('/\/([^\/]+)\/dp\/[A-Z0-9]{10}/', $productUrl, $matches)) {
            $urlTitle = $matches[1];
            // Convert URL-encoded title to readable format
            $productInfo['title'] = str_replace('-', ' ', urldecode($urlTitle));
            $productInfo['title'] = ucwords(strtolower($productInfo['title']));
        }

        LoggingService::log('Extracted product info from URL', [
            'url' => $productUrl,
            'extracted_title' => $productInfo['title'],
        ]);

        return $productInfo;
    }

    /**
     * Validate Chrome extension data format.
     */
    public function validateExtensionData(array $data): array
    {
        $errors = [];

        // Required fields
        $requiredFields = ['asin', 'country', 'product_url', 'total_reviews', 'reviews'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // ASIN format validation
        if (isset($data['asin']) && !preg_match('/^[A-Z0-9]{10}$/', $data['asin'])) {
            $errors[] = 'Invalid ASIN format';
        }

        // Country code validation
        if (isset($data['country']) && strlen($data['country']) !== 2) {
            $errors[] = 'Invalid country code format';
        }

        // Reviews validation
        if (isset($data['reviews']) && is_array($data['reviews'])) {
            foreach ($data['reviews'] as $index => $review) {
                $requiredReviewFields = ['author', 'content', 'rating', 'review_id'];
                foreach ($requiredReviewFields as $field) {
                    if (!isset($review[$field])) {
                        $errors[] = "Missing required review field '{$field}' in review {$index}";
                    }
                }

                // Rating validation
                if (isset($review['rating']) && ($review['rating'] < 1 || $review['rating'] > 5)) {
                    $errors[] = "Invalid rating in review {$index}: must be 1-5";
                }
            }
        }

        return $errors;
    }

    /**
     * Get supported Amazon domains for extension.
     */
    public function getSupportedDomains(): array
    {
        return [
            'us' => 'amazon.com',
            'ca' => 'amazon.ca',
            'gb' => 'amazon.co.uk',
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
            'be' => 'amazon.com.be',
        ];
    }
}
