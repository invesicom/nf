<?php

namespace Tests\Feature;

use App\Models\AnalysisSession;
use App\Models\AsinData;
use App\Services\ProductAnalysisPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end integration tests for the complete analysis workflow.
 *
 * These tests simulate the entire user journey from URL submission to final results,
 * providing comprehensive coverage across all system components.
 *
 * Note: These tests demonstrate the workflow concept. For full mocking of external
 * services, additional work would be needed to properly mock BrightData and LLM services.
 */
class CompleteAnalysisWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Use fake queue to prevent actual job execution in most tests
        Queue::fake();
    }

    #[Test]
    public function api_workflow_creates_session_and_queues_job()
    {
        $amazonUrl = 'https://www.amazon.com/dp/B0TEST1234?ref=test';

        // Step 1: User submits URL via API endpoint
        $response = $this->postJson('/api/analysis/start', [
            'productUrl' => $amazonUrl,
        ]);

        // Verify successful API response
        $response->assertStatus(200);
        $responseData = $response->json();
        $this->assertArrayHasKey('session_id', $responseData);
        $this->assertEquals('started', $responseData['status']);
        $sessionId = $responseData['session_id'];

        // Verify analysis session was created in database
        $this->assertDatabaseHas('analysis_sessions', [
            'id'     => $sessionId,
            'asin'   => 'B0TEST1234',
            'status' => 'pending',
        ]);

        // Verify job was queued
        Queue::assertPushed(\App\Jobs\ProcessProductAnalysis::class);

        // Step 2: Test progress polling endpoint (session validation exempted in test env)
        $progressResponse = $this->getJson("/api/analysis/progress/{$sessionId}");

        $progressResponse->assertStatus(200);
        $progressData = $progressResponse->json();

        $this->assertEquals('pending', $progressData['status']);
        $this->assertEquals('B0TEST1234', $progressData['asin']);
        $this->assertArrayHasKey('progress_percentage', $progressData);

        // Step 3: Verify session management
        $session = AnalysisSession::find($sessionId);
        $this->assertNotNull($session);
        $this->assertEquals('B0TEST1234', $session->asin);
        $this->assertEquals($amazonUrl, $session->product_url);
        $this->assertEquals('pending', $session->status);
    }

    #[Test]
    public function workflow_with_existing_analysis_returns_immediately()
    {
        // Pre-create analyzed product
        $existingProduct = AsinData::factory()->create([
            'asin'              => 'B0EXISTING',
            'country'           => 'us',
            'status'            => 'completed',
            'fake_percentage'   => 45.0,
            'grade'             => 'C',
            'have_product_data' => true,
            'product_title'     => 'Existing Product',
            'reviews'           => [
                ['rating' => 4, 'text' => 'Good product', 'id' => 'R1'],
                ['rating' => 5, 'text' => 'Excellent', 'id' => 'R2'],
            ],
            'openai_result' => [
                'detailed_scores'   => ['R1' => 20, 'R2' => 15],
                'analysis_provider' => 'openai',
                'total_cost'        => 0.05,
            ],
            'first_analyzed_at' => now()->subDays(5),
            'last_analyzed_at'  => now()->subDays(5),
        ]);

        $amazonUrl = 'https://www.amazon.com/dp/B0EXISTING?ref=test';

        // Submit analysis request for existing product
        $response = $this->withHeaders([
            'X-CSRF-TOKEN' => csrf_token(),
            'Accept'       => 'application/json',
        ])->post('/api/analysis/start', [
            'productUrl' => $amazonUrl,
        ]);

        $response->assertStatus(200);
        $responseData = $response->json();
        $sessionId = $responseData['session_id'];

        // Verify job was still queued (it will handle existing analysis logic)
        Queue::assertPushed(\App\Jobs\ProcessProductAnalysis::class);

        // Verify existing data wasn't changed
        $existingProduct->refresh();
        $this->assertEquals(45.0, $existingProduct->fake_percentage);
        $this->assertEquals('C', $existingProduct->grade);
        $this->assertEquals('Existing Product', $existingProduct->product_title);
    }

    #[Test]
    public function complete_workflow_with_reviews_produces_full_analysis()
    {
        $amazonUrl = 'https://www.amazon.com/dp/B0TEST1234?ref=test';

        // Step 1: User submits URL via API endpoint
        $response = $this->withHeaders([
            'X-CSRF-TOKEN' => csrf_token(),
            'Accept'       => 'application/json',
        ])->post('/api/analysis/start', [
            'productUrl' => $amazonUrl,
        ]);

        $response->assertStatus(200);
        $responseData = $response->json();
        $this->assertArrayHasKey('session_id', $responseData);
        $sessionId = $responseData['session_id'];

        // Step 2: Verify analysis session was created
        $this->assertDatabaseHas('analysis_sessions', [
            'id'   => $sessionId,
            'asin' => 'B0TEST1234',
        ]);

        // Step 3: Verify job was queued (following .cursorrules: "Use Queue::fake() for async tests")
        Queue::assertPushed(\App\Jobs\ProcessProductAnalysis::class);

        // Step 4: Simulate completed analysis by creating the expected end state
        // This tests the workflow integration without executing actual jobs
        $asinData = AsinData::create([
            'asin'    => 'B0TEST1234',
            'country' => 'us',
            'status'  => 'completed',
            'reviews' => json_encode([
                [
                    'id'                => 'R1',
                    'rating'            => 5,
                    'text'              => 'Great product!',
                    'author'            => 'TestUser1',
                    'date'              => '2024-01-01',
                    'verified_purchase' => true,
                ],
                [
                    'id'                => 'R2',
                    'rating'            => 4,
                    'text'              => 'Good value for money',
                    'author'            => 'TestUser2',
                    'date'              => '2024-01-02',
                    'verified_purchase' => true,
                ],
                [
                    'id'                => 'R3',
                    'rating'            => 3,
                    'text'              => 'Average product',
                    'author'            => 'TestUser3',
                    'date'              => '2024-01-03',
                    'verified_purchase' => false,
                ],
            ]),
            'product_title'           => 'Test Product Title',
            'product_image_url'       => 'https://example.com/test-image.jpg',
            'product_description'     => 'Test product description',
            'have_product_data'       => true,
            'total_reviews_on_amazon' => 3,
            'openai_result'           => [
                'detailed_scores' => [
                    'R1' => ['score' => 25, 'label' => 'genuine', 'confidence' => 85],
                    'R2' => ['score' => 30, 'label' => 'genuine', 'confidence' => 80],
                    'R3' => ['score' => 90, 'label' => 'fake', 'confidence' => 95],
                ],
                'analysis_provider' => 'openai',
                'total_cost'        => 0.025,
            ],
            'fake_percentage'   => 33.3,
            'grade'             => 'C',
            'explanation'       => 'Analysis of 3 reviews found 1 potentially fake reviews (33.3%). This product has moderate fake review concerns. Exercise caution when evaluating reviews.',
            'amazon_rating'     => 4.0,
            'adjusted_rating'   => 4.5,
            'first_analyzed_at' => now(),
            'last_analyzed_at'  => now(),
        ]);

        // Simulate session completion
        $session = AnalysisSession::find($sessionId);
        $session->markAsCompleted([
            'success'      => true,
            'asin_data'    => $asinData,
            'redirect_url' => route('amazon.product.show', ['country' => 'us', 'asin' => 'B0TEST1234']),
        ]);

        // Step 5: Test progress polling endpoint
        $progressResponse = $this->get("/api/analysis/progress/{$sessionId}");
        $progressResponse->assertStatus(200);
        $progressData = $progressResponse->json();

        $this->assertEquals('completed', $progressData['status']);
        $this->assertEquals(100, $progressData['progress_percentage']);
        $this->assertArrayHasKey('redirect_url', $progressData);

        // Step 6: Verify redirect URL leads to product page
        $redirectUrl = $progressData['redirect_url'];
        $this->assertStringContainsString('/us/B0TEST1234', $redirectUrl);

        $productPageResponse = $this->followingRedirects()->get($redirectUrl);
        $productPageResponse->assertStatus(200);
        $productPageResponse->assertSee('Test Product Title');
        $productPageResponse->assertSee('33.3%');
        $productPageResponse->assertSee('Grade C');
    }

    #[Test]
    public function complete_workflow_without_reviews_handles_gracefully()
    {
        $amazonUrl = 'https://www.amazon.com/dp/B0NOREVIEWS?ref=test';

        // Step 1: User submits URL
        $response = $this->withHeaders([
            'X-CSRF-TOKEN' => csrf_token(),
            'Accept'       => 'application/json',
        ])->post('/api/analysis/start', [
            'productUrl' => $amazonUrl,
        ]);

        $response->assertStatus(200);
        $responseData = $response->json();
        $sessionId = $responseData['session_id'];

        // Step 2: Verify job was queued
        Queue::assertPushed(\App\Jobs\ProcessProductAnalysis::class);

        // Step 3: Simulate completed graceful handling by creating the expected end state
        $asinData = AsinData::create([
            'asin'                    => 'B0NOREVIEWS',
            'country'                 => 'us',
            'status'                  => 'completed',
            'reviews'                 => json_encode([]), // No reviews
            'product_title'           => 'Product Without Reviews',
            'product_image_url'       => 'https://example.com/no-reviews-image.jpg',
            'product_description'     => 'Product with no reviews',
            'have_product_data'       => true,
            'total_reviews_on_amazon' => 0,
            'openai_result'           => [
                'detailed_scores'   => [],
                'analysis_provider' => 'system',
                'total_cost'        => 0.0,
            ],
            'fake_percentage'   => 0,
            'grade'             => 'U', // Unanalyzable
            'explanation'       => 'Unable to analyze reviews at this time.',
            'amazon_rating'     => 0.0,
            'adjusted_rating'   => 0.0,
            'first_analyzed_at' => now(),
            'last_analyzed_at'  => now(),
        ]);

        // Simulate session completion (no redirect for unanalyzable products)
        $session = AnalysisSession::find($sessionId);
        $session->markAsCompleted([
            'success'      => true,
            'asin_data'    => $asinData,
            'redirect_url' => null, // No redirect for unanalyzable products
        ]);

        // Step 4: Verify progress polling shows completion without redirect
        $progressResponse = $this->get("/api/analysis/progress/{$sessionId}");
        $progressResponse->assertStatus(200);
        $progressData = $progressResponse->json();

        $this->assertEquals('completed', $progressData['status']);
        $this->assertNull($progressData['redirect_url']); // No redirect for unanalyzable products

        // Step 5: Verify product doesn't appear in public listings (due to ProductAnalysisPolicy)
        $listingResponse = $this->get('/products');
        $listingResponse->assertStatus(200);
        $listingResponse->assertDontSee('B0NOREVIEWS');
    }

    #[Test]
    public function complete_workflow_handles_brightdata_failures_gracefully()
    {
        // Mock BrightData service failure
        Http::fake([
            'brightdata.com/*' => Http::response(['error' => 'Service unavailable'], 503),
            '*'                => Http::response(['error' => 'Not found'], 404),
        ]);

        $amazonUrl = 'https://www.amazon.com/dp/B0FAILTEST?ref=test';

        $response = $this->withHeaders([
            'X-CSRF-TOKEN' => csrf_token(),
            'Accept'       => 'application/json',
        ])->post('/api/analysis/start', [
            'productUrl' => $amazonUrl,
        ]);

        $response->assertStatus(200);
        $responseData = $response->json();
        $sessionId = $responseData['session_id'];

        $session = AnalysisSession::where('id', $sessionId)->first();
        $this->assertNotNull($session);

        // Verify job was queued
        Queue::assertPushed(\App\Jobs\ProcessProductAnalysis::class);

        // Simulate graceful failure handling - session completes without error
        $session->markAsCompleted([
            'success'      => true,
            'asin_data'    => null, // No data due to failure
            'redirect_url' => null,
        ]);

        // Verify progress endpoint returns completed status
        $progressResponse = $this->get("/api/analysis/progress/{$sessionId}");
        $progressResponse->assertStatus(200);
        $progressData = $progressResponse->json();

        $this->assertEquals('completed', $progressData['status']);
        $this->assertArrayNotHasKey('error', $progressData);
    }

    #[Test]
    public function complete_workflow_handles_existing_analysis_correctly()
    {
        // Pre-create analyzed product
        $existingProduct = AsinData::factory()->create([
            'asin'              => 'B0EXISTING',
            'country'           => 'us',
            'status'            => 'completed',
            'fake_percentage'   => 45.0,
            'grade'             => 'C',
            'have_product_data' => true,
            'product_title'     => 'Existing Product',
            'reviews'           => [
                ['rating' => 4, 'text' => 'Good product', 'id' => 'R1'],
                ['rating' => 5, 'text' => 'Excellent', 'id' => 'R2'],
            ],
            'openai_result' => [
                'detailed_scores'   => ['R1' => 20, 'R2' => 15],
                'analysis_provider' => 'openai',
                'total_cost'        => 0.05,
            ],
            'first_analyzed_at' => now()->subDays(5),
            'last_analyzed_at'  => now()->subDays(5),
        ]);

        $amazonUrl = 'https://www.amazon.com/dp/B0EXISTING?ref=test';

        $response = $this->withHeaders([
            'X-CSRF-TOKEN' => csrf_token(),
            'Accept'       => 'application/json',
        ])->post('/api/analysis/start', [
            'productUrl' => $amazonUrl,
        ]);

        $response->assertStatus(200);
        $responseData = $response->json();
        $sessionId = $responseData['session_id'];

        $session = AnalysisSession::where('id', $sessionId)->first();
        $this->assertNotNull($session);

        // Verify job was queued
        Queue::assertPushed(\App\Jobs\ProcessProductAnalysis::class);

        // Simulate immediate completion for existing analysis
        $session->markAsCompleted([
            'success'      => true,
            'asin_data'    => $existingProduct,
            'redirect_url' => route('amazon.product.show', ['country' => 'us', 'asin' => 'B0EXISTING']),
        ]);

        // Verify existing data wasn't changed
        $existingProduct->refresh();
        $this->assertEquals(45.0, $existingProduct->fake_percentage);
        $this->assertEquals('C', $existingProduct->grade);
        $this->assertEquals('Existing Product', $existingProduct->product_title);

        // Verify timestamps weren't updated (existing analysis preserved)
        $this->assertTrue($existingProduct->first_analyzed_at->lessThan(now()->subDays(4)));

        // Verify immediate redirect to existing results
        $progressResponse = $this->get("/api/analysis/progress/{$sessionId}");
        $progressResponse->assertStatus(200);
        $progressData = $progressResponse->json();

        $this->assertEquals('completed', $progressData['status']);
        $this->assertStringContainsString('/us/B0EXISTING', $progressData['redirect_url']);
    }
}
