<?php

namespace Tests\Unit;

use App\Services\AlertManager;
use App\Services\AlertService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Mockery;

class AlertManagerTest extends TestCase
{
    private AlertManager $alertManager;
    private AlertService $mockAlertService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache
        Cache::flush();
        
        // Mock AlertService
        $this->mockAlertService = Mockery::mock(AlertService::class);
        $this->alertManager = new AlertManager($this->mockAlertService);
        
        // Enable logging spy
        Log::spy();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_all_failures_regardless_of_alert_threshold()
    {
        // No alert should be triggered for single failure
        $this->mockAlertService->shouldNotReceive('connectivityIssue');

        $this->alertManager->recordFailure(
            'BrightData Web Scraper',
            'SCRAPING_FAILED',
            'Test failure',
            ['asin' => 'B0TEST1234']
        );

        Log::shouldHaveReceived('warning')
           ->once()
           ->with('Service failure recorded: BrightData Web Scraper', Mockery::type('array'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_suppresses_single_brightdata_failures()
    {
        // Single failure should not trigger alert (below threshold)
        $this->mockAlertService->shouldNotReceive('connectivityIssue');

        $this->alertManager->recordFailure(
            'BrightData Web Scraper',
            'SCRAPING_FAILED',
            'Single failure test',
            ['asin' => 'B0TEST1234']
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_alerts_on_error_rate_threshold_for_primary_services()
    {
        // For PRIMARY services, need >5 failures in 10 minutes for HIGH_P1 alert
        $this->mockAlertService->shouldReceive('connectivityIssue')
                               ->once()
                               ->withArgs(function($service, $errorType, $message, $context) {
                                   return $service === 'BrightData API' && 
                                          $errorType === 'JOB_TRIGGER_FAILED' &&
                                          isset($context['alert_level']) &&
                                          $context['alert_level'] === 'HIGH_P1';
                               });

        // Record 6 failures quickly to trigger HIGH_P1 threshold
        for ($i = 0; $i < 6; $i++) {
            $this->alertManager->recordFailure(
                'BrightData API',
                'JOB_TRIGGER_FAILED',
                'API connection failed',
                ['dataset_id' => 'test', 'attempt' => $i]
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_reduces_severity_for_fallback_services()
    {
        // Fallback services should have reduced alerting
        $this->mockAlertService->shouldNotReceive('connectivityIssue');

        // Even multiple failures of fallback services should be suppressed or have low priority
        for ($i = 0; $i < 4; $i++) {
            $this->alertManager->recordFailure(
                'Amazon Direct Scraping',
                'SCRAPING_FAILED',
                'Expected fallback failure',
                ['asin' => 'B0TEST' . $i]
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_recovery_suppression()
    {
        // Record a recovery
        $this->alertManager->recordRecovery('BrightData Web Scraper', 'SCRAPING_FAILED');

        // No alert should be sent for failures after recovery
        $this->mockAlertService->shouldNotReceive('connectivityIssue');

        $this->alertManager->recordFailure(
            'BrightData Web Scraper',
            'SCRAPING_FAILED',
            'Failure after recovery',
            ['asin' => 'B0TEST1234']
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_throttling()
    {
        // First set of failures should trigger alert once
        $this->mockAlertService->shouldReceive('connectivityIssue')
                               ->once()
                               ->withArgs(function($service, $errorType, $message, $context) {
                                   return isset($context['alert_level']);
                               });

        // Trigger initial alert (6 failures for HIGH_P1)
        for ($i = 0; $i < 6; $i++) {
            $this->alertManager->recordFailure(
                'BrightData API',
                'JOB_TRIGGER_FAILED',
                'API failure',
                ['attempt' => $i]
            );
        }

        // Additional failures should be throttled (no additional alerts)
        for ($i = 7; $i < 10; $i++) {
            $this->alertManager->recordFailure(
                'BrightData API',
                'JOB_TRIGGER_FAILED',
                'Should be throttled',
                ['attempt' => $i]
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_provides_error_rate_information()
    {
        // Don't expect any alerts for this test
        $this->mockAlertService->shouldNotReceive('connectivityIssue');

        // Record some failures
        for ($i = 0; $i < 3; $i++) {
            $this->alertManager->recordFailure(
                'OpenAI Service',
                'API_ERROR',
                'Test error',
                ['attempt' => $i]
            );
        }

        $errorRate = $this->alertManager->getErrorRate('OpenAI Service', 'API_ERROR', 10);

        $this->assertEquals(3, $errorRate['failures']);
        $this->assertEquals(10, $errorRate['window_minutes']);
        $this->assertEquals(0.3, $errorRate['rate_per_minute']);
        $this->assertCount(3, $errorRate['timestamps']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_includes_business_impact_in_alert_context()
    {
        $this->mockAlertService->shouldReceive('connectivityIssue')
                               ->once()
                               ->withArgs(function($service, $errorType, $message, $context) {
                                   return isset($context['business_impact']) &&
                                          isset($context['service_criticality']) &&
                                          isset($context['recommended_action']) &&
                                          $context['service_criticality'] === 'PRIMARY' &&
                                          $context['alert_level'] === 'CRITICAL_P0';
                               });

        // Trigger CRITICAL_P0 alert with enough failures (>10 in 5 minutes)
        for ($i = 0; $i < 11; $i++) {
            $this->alertManager->recordFailure(
                'BrightData Web Scraper',
                'SCRAPING_FAILED',
                'Critical failure',
                ['asin' => 'B0TEST' . $i]
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maps_to_correct_alert_service_methods()
    {
        // Test timeout mapping - should call apiTimeout
        $this->mockAlertService->shouldReceive('apiTimeout')
                               ->once()
                               ->withArgs(function($service, $asin, $duration, $context) {
                                   return $service === 'BrightData Web Scraper' &&
                                          $asin === 'B0TEST1234' &&
                                          $duration === 300 &&
                                          isset($context['alert_level']);
                               });

        // Trigger timeout error with enough failures for CRITICAL_P0
        for ($i = 0; $i < 11; $i++) {
            $this->alertManager->recordFailure(
                'BrightData Web Scraper',
                'POLLING_TIMEOUT',
                'Timeout occurred',
                ['asin' => 'B0TEST1234', 'timeout_duration' => 300]
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_openai_quota_exceeded_correctly()
    {
        // OpenAI quota should map to openaiQuotaExceeded method
        $this->mockAlertService->shouldReceive('openaiQuotaExceeded')
                               ->once()
                               ->withArgs(function($message, $context) {
                                   return isset($context['business_impact']['revenue_affecting']) &&
                                          $context['business_impact']['revenue_affecting'] === true &&
                                          $context['alert_level'] === 'MEDIUM_P2';
                               });

        // OpenAI is CORE service - HIGH_P1 becomes MEDIUM_P2 (3 failures in 15 min)
        // But we need 4 failures to trigger MEDIUM_P2 threshold
        for ($i = 0; $i < 4; $i++) {
            $this->alertManager->recordFailure(
                'OpenAI Service',
                'QUOTA_EXCEEDED',
                'Quota exceeded',
                ['status_code' => 429]
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_provides_recommended_actions()
    {
        $this->mockAlertService->shouldReceive('connectivityIssue')
                               ->once()
                               ->withArgs(function($service, $errorType, $message, $context) {
                                   return isset($context['recommended_action']) &&
                                          str_contains($context['recommended_action'], 'BrightData API status') &&
                                          $context['alert_level'] === 'HIGH_P1';
                               });

        // Trigger BrightData API alert (HIGH_P1 for PRIMARY service)
        for ($i = 0; $i < 6; $i++) {
            $this->alertManager->recordFailure(
                'BrightData API',
                'JOB_TRIGGER_FAILED',
                'API connection failed',
                ['dataset_id' => 'test']
            );
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
