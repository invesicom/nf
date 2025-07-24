<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductAnalysis;
use App\Livewire\ReviewAnalyzer;
use App\Models\AnalysisSession;
use App\Models\AsinData;
use App\Services\Amazon\AmazonScrapingService;
use App\Services\CaptchaService;
use App\Services\OpenAIService;
use App\Services\ReviewAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AsyncProgressFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock external services to avoid real API calls
        Http::fake(['*' => Http::response('Mock response', 200)]);
        
        $this->mock(AmazonScrapingService::class, function ($mock) {
            $mock->shouldReceive('fetchReviews')->andReturn(['reviews' => []]);
            $mock->shouldIgnoreMissing();
        });
        
        $this->mock(OpenAIService::class, function ($mock) {
            $mock->shouldReceive('analyzeReviews')->andReturn(['detailed_scores' => [0 => 25]]);
            $mock->shouldIgnoreMissing();
        });
        
        $this->mock(CaptchaService::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturn(true);
            $mock->shouldReceive('getProvider')->andReturn('recaptcha');
            $mock->shouldIgnoreMissing();
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function async_mode_shows_progress_bar_immediately()
    {
        // Force async mode
        config(['analysis.async_enabled' => true]);
        
        $component = Livewire::test(ReviewAnalyzer::class);
        
        // Initially not loading
        $component->assertSet('loading', false);
        
        // Set a product URL and trigger analysis
        $component->set('productUrl', 'https://www.amazon.com/dp/B08N5WRWNW')
                  ->call('analyze');
        
        // Should immediately show loading state in async mode
        $component->assertSet('loading', true);
        $component->assertSet('progressPercentage', 0);
        $component->assertSet('currentlyProcessing', 'Starting analysis...');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function async_polling_maintains_loading_state()
    {
        config(['analysis.async_enabled' => true]);
        
        $component = Livewire::test(ReviewAnalyzer::class);
        
        // Simulate the JavaScript calling startAsyncPolling
        $component->call('startAsyncPolling');
        
        // Should be in loading state
        $component->assertSet('loading', true);
        $component->assertSet('progressPercentage', 0);
        $component->assertSet('currentlyProcessing', 'Starting analysis...');
    }

    #[\PHPUnit\Framework\Attributes\Test] 
    public function async_progress_updates_work_correctly()
    {
        config(['analysis.async_enabled' => true]);
        
        $component = Livewire::test(ReviewAnalyzer::class);
        
        // Start async polling
        $component->call('startAsyncPolling');
        
        // Simulate progress updates from JavaScript polling
        $component->call('updateAsyncProgress', [
            'progress' => 25,
            'message' => 'Validating product URL...',
            'step' => 1
        ]);
        
        $component->assertSet('loading', true);
        $component->assertSet('progressPercentage', 25);
        $component->assertSet('currentlyProcessing', 'Validating product URL...');
        $component->assertSet('progressStep', 1);
        
        // More progress
        $component->call('updateAsyncProgress', [
            'progress' => 50,
            'message' => 'Fetching product reviews...',
            'step' => 3
        ]);
        
        $component->assertSet('progressPercentage', 50);
        $component->assertSet('currentlyProcessing', 'Fetching product reviews...');
        $component->assertSet('progressStep', 3);
        
        // Final progress
        $component->call('updateAsyncProgress', [
            'progress' => 100,
            'message' => 'Analysis complete!',
            'step' => 8
        ]);
        
        $component->assertSet('progressPercentage', 100);
        $component->assertSet('currentlyProcessing', 'Analysis complete!');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function async_completion_works_correctly()
    {
        config(['analysis.async_enabled' => true]);
        
        $component = Livewire::test(ReviewAnalyzer::class);
        
        // Start with loading state
        $component->call('startAsyncPolling');
        $component->assertSet('loading', true);
        
        // Simulate completion
        $component->call('handleAsyncCompletion', [
            'result' => [
                'fake_percentage' => 15.5,
                'grade' => 'B',
                'explanation' => 'Good product with minimal fake reviews',
                'amazon_rating' => 4.5,
                'adjusted_rating' => 4.2
            ]
        ]);
        
        // Should no longer be loading
        $component->assertSet('loading', false);
        $component->assertSet('progressPercentage', 100);
        $component->assertSet('currentlyProcessing', 'Analysis complete!');
        
        // Should have results
        $component->assertSet('fake_percentage', 15.5);
        $component->assertSet('grade', 'B');
        $component->assertSet('explanation', 'Good product with minimal fake reviews');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function async_error_handling_works_correctly()
    {
        config(['analysis.async_enabled' => true]);
        
        $component = Livewire::test(ReviewAnalyzer::class);
        
        // Start with loading state  
        $component->call('startAsyncPolling');
        $component->assertSet('loading', true);
        
        // Simulate error
        $component->call('handleAsyncError', 'Product not found on Amazon');
        
        // Should no longer be loading and show error
        $component->assertSet('loading', false);
        $component->assertSet('error', 'Product not found on Amazon');
        $component->assertSet('currentlyProcessing', 'Analysis failed');
        $component->assertSet('progressPercentage', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function progress_bar_elements_render_in_async_mode()
    {
        config(['analysis.async_enabled' => true]);
        
        $component = Livewire::test(ReviewAnalyzer::class);
        
        // Start async polling to show progress bar
        $component->call('startAsyncPolling');
        
        // Get the rendered view from Livewire component
        $view = $component->viewData('loading');
        
        // Check that loading state is true (which means progress bar should be visible)
        $this->assertTrue($view);
        
        // Check that progress elements are set correctly
        $component->assertSet('loading', true);
        $component->assertSet('progressPercentage', 0);
        $component->assertSet('currentlyProcessing', 'Starting analysis...');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function complete_async_flow_simulation()
    {
        config(['analysis.async_enabled' => true]);
        
        $component = Livewire::test(ReviewAnalyzer::class);
        
        // Step 1: User clicks analyze
        $component->set('productUrl', 'https://www.amazon.com/dp/B08N5WRWNW')
                  ->call('analyze');
        
        // Should immediately show loading
        $component->assertSet('loading', true);
        
        // Step 2: JavaScript calls startAsyncPolling  
        $component->call('startAsyncPolling');
        
        // Step 3: Progress updates from polling
        $progressSteps = [
            ['progress' => 12, 'message' => 'Validating product URL...', 'step' => 1],
            ['progress' => 25, 'message' => 'Checking product database...', 'step' => 2], 
            ['progress' => 37, 'message' => 'Fetching product reviews...', 'step' => 3],
            ['progress' => 50, 'message' => 'Processing reviews...', 'step' => 4],
            ['progress' => 62, 'message' => 'Analyzing reviews with AI...', 'step' => 5],
            ['progress' => 75, 'message' => 'Calculating metrics...', 'step' => 6],
            ['progress' => 87, 'message' => 'Fetching product data...', 'step' => 7],
            ['progress' => 100, 'message' => 'Analysis complete!', 'step' => 8]
        ];
        
        foreach ($progressSteps as $step) {
            $component->call('updateAsyncProgress', $step);
            
            $component->assertSet('loading', true);
            $component->assertSet('progressPercentage', $step['progress']);
            $component->assertSet('currentlyProcessing', $step['message']);
            $component->assertSet('progressStep', $step['step']);
        }
        
        // Step 4: Analysis completion
        $component->call('handleAsyncCompletion', [
            'result' => [
                'fake_percentage' => 8.5,
                'grade' => 'A',
                'explanation' => 'Excellent product with authentic reviews',
                'amazon_rating' => 4.8,
                'adjusted_rating' => 4.7
            ]
        ]);
        
        // Final state verification
        $component->assertSet('loading', false);
        $component->assertSet('isAnalyzed', true);
        $component->assertSet('fake_percentage', 8.5);
        $component->assertSet('grade', 'A');
    }
} 