<?php

namespace App\Http\Controllers;

use App\Models\AsinData;
use App\Services\ExtensionReviewService;
use App\Services\LoggingService;
use App\Services\MetricsCalculationService;
use App\Services\ReviewAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExtensionController extends Controller
{
    private ExtensionReviewService $extensionService;
    private ReviewAnalysisService $analysisService;
    private MetricsCalculationService $metricsService;

    public function __construct(
        ExtensionReviewService $extensionService,
        ReviewAnalysisService $analysisService,
        MetricsCalculationService $metricsService
    ) {
        $this->extensionService = $extensionService;
        $this->analysisService = $analysisService;
        $this->metricsService = $metricsService;
    }

    /**
     * Submit review data from Chrome extension.
     */
    public function submitReviews(Request $request): JsonResponse
    {
        // Validate API key (skip in local/testing environments)
        if (!app()->environment(['local', 'testing']) && !$this->validateApiKey($request)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid or missing API key',
            ], 401);
        }

        // Validate the JSON structure
        $rules = [
            'asin' => 'required|string|regex:/^[A-Z0-9]{10}$/',
            'country' => 'required|string|size:2',
            'product_url' => 'required|url',
            'extraction_timestamp' => 'required|date_format:Y-m-d\TH:i:s.v\Z',
            'extension_version' => 'required|string',
            'reviews' => 'present|array',
            // Product information from Chrome extension (replaces backend scraping)
            'product_info' => 'required|array',
            'product_info.title' => 'required|string|max:500',
            'product_info.description' => 'nullable|string|max:2000',
            'product_info.image_url' => 'nullable|url|max:500',
            'product_info.amazon_rating' => 'nullable|numeric|min:0|max:5',
            'product_info.total_reviews_on_amazon' => 'required|integer|min:0',
            'product_info.price' => 'nullable|string|max:50',
            'product_info.availability' => 'nullable|string|max:100',
        ];

        // Only add review validation rules if reviews array is not empty
        if (!empty($request->input('reviews'))) {
            $rules = array_merge($rules, [
                'reviews.*.author' => 'required|string',
                'reviews.*.content' => 'required|string',
                'reviews.*.date' => 'required|date_format:Y-m-d',
                'reviews.*.extraction_index' => 'required|integer|min:1',
                'reviews.*.helpful_votes' => 'required|integer|min:0',
                'reviews.*.rating' => 'required|integer|min:1|max:5',
                'reviews.*.review_id' => 'required|string',
                'reviews.*.title' => 'required|string',
                'reviews.*.verified_purchase' => 'required|boolean',
                'reviews.*.vine_customer' => 'required|boolean',
            ]);
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid data format',
                'details' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $request->all();
            
            LoggingService::log('Chrome extension data received', [
                'asin' => $data['asin'],
                'country' => $data['country'],
                'review_count' => count($data['reviews']),
                'extension_version' => $data['extension_version'],
            ]);

            // Process the extension data
            $asinData = $this->extensionService->processExtensionData($data);

            // Perform analysis
            $analysisResult = $this->analysisService->analyzeWithLLM($asinData);
            $metrics = $this->metricsService->calculateFinalMetrics($analysisResult);
            
            // Get updated model with final metrics
            $asinData = $asinData->fresh();

            // Build response with detailed analysis results (matching front-end display)
            $reviewsAnalyzed = count($asinData->getReviewsArray());
            $fakeReviewCount = round(($asinData->fake_percentage / 100) * $reviewsAnalyzed);
            $genuineReviewCount = $reviewsAnalyzed - $fakeReviewCount;
            $ratingDifference = ($asinData->adjusted_rating ?? 0) - ($asinData->amazon_rating ?? 0);
            
            $response = [
                'success' => true,
                'asin' => $data['asin'],
                'country' => $data['country'],
                'analysis_id' => $asinData->id,
                'processed_reviews' => $reviewsAnalyzed,
                'analysis_complete' => true,
                'results' => [
                    'fake_percentage' => $asinData->fake_percentage ?? 0,
                    'grade' => $asinData->grade,
                    'explanation' => $asinData->explanation,
                    'amazon_rating' => $asinData->amazon_rating ?? 0,
                    'adjusted_rating' => $asinData->adjusted_rating ?? 0,
                    'rating_difference' => round($ratingDifference, 2),
                ],
                'statistics' => [
                    'total_reviews_on_amazon' => $asinData->total_reviews_on_amazon ?? null,
                    'reviews_analyzed' => $reviewsAnalyzed,
                    'genuine_reviews' => $genuineReviewCount,
                    'fake_reviews' => $fakeReviewCount,
                ],
                'product_info' => [
                    'title' => $asinData->product_title,
                    'description' => $asinData->product_description,
                    'image_url' => $asinData->product_image_url,
                ],
                'view_url' => route('amazon.product.show', [
                    'asin' => $data['asin'],
                    'country' => $data['country'],
                ]),
                'redirect_url' => route('amazon.product.show', [
                    'asin' => $data['asin'],
                    'country' => $data['country'],
                ]),
            ];

            LoggingService::log('Chrome extension analysis completed', [
                'asin' => $data['asin'],
                'fake_percentage' => $asinData->fake_percentage,
                'grade' => $asinData->grade,
            ]);

            return response()->json($response);

        } catch (\Exception $e) {
            LoggingService::handleException($e, 'Chrome extension data processing failed');

            return response()->json([
                'success' => false,
                'error' => 'Failed to process review data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get analysis status for extension.
     */
    public function getAnalysisStatus(Request $request, string $asin, string $country): JsonResponse
    {
        if (!app()->environment(['local', 'testing']) && !$this->validateApiKey($request)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid or missing API key',
            ], 401);
        }

        $asinData = AsinData::where('asin', $asin)
            ->where('country', $country)
            ->first();

        if (!$asinData) {
            return response()->json([
                'success' => false,
                'error' => 'Analysis not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'asin' => $asin,
            'country' => $country,
            'status' => $asinData->status,
            'fake_percentage' => $asinData->fake_percentage,
            'grade' => $asinData->grade,
            'view_url' => route('amazon.product.show', [
                'asin' => $asin,
                'country' => $country,
            ]),
        ]);
    }

    /**
     * Validate API key from request.
     */
    private function validateApiKey(Request $request): bool
    {
        $apiKey = $request->header('X-API-Key') ?? $request->input('api_key');
        $validApiKey = config('services.extension.api_key');

        if (!$validApiKey) {
            LoggingService::log('Extension API key not configured');
            return false;
        }

        return hash_equals($validApiKey, $apiKey ?? '');
    }
}
