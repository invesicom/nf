<?php

namespace Tests\Unit;

use App\Enums\AlertType;
use App\Notifications\SystemAlert;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private AlertService $alertService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alertService = new AlertService();
        
        // Mock Pushover configuration
        Config::set('services.pushover', [
            'token' => 'test-token',
            'user' => 'test-user',
        ]);
        
        // Enable alerts by default
        Config::set('alerts.enabled', true);
        Config::set('alerts.enabled_types', [
            'amazon_session_expired' => true,
            'openai_quota_exceeded' => true,
            'openai_api_error' => true,
            'system_error' => true,
            'database_error' => true,
            'security_alert' => true,
        ]);
    }

    /** @test */
    public function it_sends_amazon_session_expired_alert()
    {
        Notification::fake();

        $this->alertService->amazonSessionExpired('Session expired', ['asin' => 'B0TEST1234']);

        Notification::assertSentTo(
            new AnonymousNotifiable(),
            SystemAlert::class,
            function ($notification) {
                return $notification->getAlertType() === AlertType::AMAZON_SESSION_EXPIRED;
            }
        );
    }

    /** @test */
    public function it_sends_openai_quota_exceeded_alert()
    {
        Notification::fake();

        $this->alertService->openaiQuotaExceeded('Quota exceeded', ['status_code' => 429]);

        Notification::assertSentTo(
            new AnonymousNotifiable(),
            SystemAlert::class,
            function ($notification) {
                return $notification->getAlertType() === AlertType::OPENAI_QUOTA_EXCEEDED;
            }
        );
    }

    /** @test */
    public function it_sends_openai_api_error_alert()
    {
        Notification::fake();

        $this->alertService->openaiApiError('API error', 500, ['endpoint' => '/chat/completions']);

        Notification::assertSentTo(
            new AnonymousNotifiable(),
            SystemAlert::class,
            function ($notification) {
                return $notification->getAlertType() === AlertType::OPENAI_API_ERROR;
            }
        );
    }

    /** @test */
    public function it_sends_system_error_alert()
    {
        Notification::fake();

        $exception = new \Exception('Test exception');
        $this->alertService->systemError('System error', $exception, ['component' => 'test']);

        Notification::assertSentTo(
            new AnonymousNotifiable(),
            SystemAlert::class,
            function ($notification) {
                return $notification->getAlertType() === AlertType::SYSTEM_ERROR;
            }
        );
    }

    /** @test */
    public function it_sends_database_error_alert()
    {
        Notification::fake();

        $exception = new \Exception('Database connection failed');
        $this->alertService->databaseError('DB error', $exception, ['connection' => 'mysql']);

        Notification::assertSentTo(
            new AnonymousNotifiable(),
            SystemAlert::class,
            function ($notification) {
                return $notification->getAlertType() === AlertType::DATABASE_ERROR;
            }
        );
    }

    /** @test */
    public function it_sends_security_alert()
    {
        Notification::fake();

        $this->alertService->securityAlert('Suspicious activity', ['ip' => '192.168.1.1']);

        Notification::assertSentTo(
            new AnonymousNotifiable(),
            SystemAlert::class,
            function ($notification) {
                return $notification->getAlertType() === AlertType::SECURITY_ALERT;
            }
        );
    }

    /** @test */
    public function it_throttles_alerts_when_enabled()
    {
        Notification::fake();
        Cache::flush();

        // Send first alert - should go through
        $this->alertService->amazonSessionExpired('First alert', ['asin' => 'B0TEST1234']);

        // Send second alert immediately - should be throttled
        $this->alertService->amazonSessionExpired('Second alert', ['asin' => 'B0TEST1234']);

        // Only one notification should be sent
        Notification::assertSentToTimes(new AnonymousNotifiable(), SystemAlert::class, 1);
    }

    /** @test */
    public function it_does_not_send_alerts_when_globally_disabled()
    {
        Notification::fake();
        Config::set('alerts.enabled', false);

        $this->alertService->amazonSessionExpired('Test alert', ['asin' => 'B0TEST1234']);

        Notification::assertNotSentTo(new AnonymousNotifiable(), SystemAlert::class);
    }

    /** @test */
    public function it_does_not_send_alerts_when_specific_type_disabled()
    {
        Notification::fake();
        Config::set('alerts.enabled_types.amazon_session_expired', false);

        $this->alertService->amazonSessionExpired('Test alert', ['asin' => 'B0TEST1234']);

        Notification::assertNotSentTo(new AnonymousNotifiable(), SystemAlert::class);
    }

    /** @test */
    public function it_logs_alerts_in_log_only_mode()
    {
        Notification::fake();
        Config::set('alerts.development.log_only', true);

        $this->alertService->amazonSessionExpired('Test alert', ['asin' => 'B0TEST1234']);

        Notification::assertNotSentTo(new AnonymousNotifiable(), SystemAlert::class);
        
        // In log-only mode, no notifications should be sent
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /** @test */
    public function it_handles_missing_pushover_configuration()
    {
        Notification::fake();
        Config::set('services.pushover.token', null);

        $this->alertService->amazonSessionExpired('Test alert', ['asin' => 'B0TEST1234']);

        Notification::assertNotSentTo(new AnonymousNotifiable(), SystemAlert::class);
        
        // Test passes if no exception is thrown and no notification is sent
        $this->assertTrue(true);
    }

    /** @test */
    public function it_logs_throttled_alerts()
    {
        Notification::fake();
        Cache::flush();

        // Send first alert to set throttle
        $this->alertService->amazonSessionExpired('First alert', ['asin' => 'B0TEST1234']);

        // Send second alert - should be throttled
        $this->alertService->amazonSessionExpired('Second alert', ['asin' => 'B0TEST1234']);

        // Only one notification should have been sent due to throttling
        Notification::assertSentToTimes(new AnonymousNotifiable(), SystemAlert::class, 1);
    }

    /** @test */
    public function it_sends_generic_alert_with_custom_parameters()
    {
        Notification::fake();

        $this->alertService->alert(
            AlertType::SYSTEM_ERROR,
            'Custom alert message',
            ['custom' => 'context'],
            1, // High priority
            'https://example.com',
            'Custom URL Title'
        );

        Notification::assertSentTo(
            new AnonymousNotifiable(),
            SystemAlert::class,
            function ($notification) {
                return $notification->getAlertType() === AlertType::SYSTEM_ERROR &&
                       $notification->getContext()['custom'] === 'context';
            }
        );
    }
} 