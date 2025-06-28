<?php

namespace Tests\Unit;

use App\Notifications\Channels\PushoverChannel;
use App\Notifications\SystemAlert;
use App\Enums\AlertType;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushoverChannelTest extends TestCase
{
    private PushoverChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new PushoverChannel();
    }

    /** @test */
    public function it_sends_successful_pushover_notification()
    {
        Http::fake([
            'api.pushover.net/*' => Http::response([
                'status' => 1,
                'request' => '647d2300-702c-4b38-8b2f-d56326ae460b'
            ], 200)
        ]);

        $notification = new SystemAlert(
            AlertType::AMAZON_SESSION_EXPIRED,
            'Test message',
            ['test' => true]
        );

        $notifiable = new \stdClass();
        $response = $this->channel->send($notifiable, $notification);

        $this->assertTrue($response->successful());
        
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.pushover.net/1/messages.json' &&
                   $request->method() === 'POST';
        });
    }

    /** @test */
    public function it_handles_pushover_api_errors()
    {
        Http::fake([
            'api.pushover.net/*' => Http::response([
                'user' => 'invalid',
                'errors' => ['user identifier is invalid'],
                'status' => 0,
                'request' => '5042853c-402d-4a18-abcb-168734a801de'
            ], 400)
        ]);

        $notification = new SystemAlert(
            AlertType::AMAZON_SESSION_EXPIRED,
            'Test message',
            ['test' => true]
        );

        $notifiable = new \stdClass();
        $response = $this->channel->send($notifiable, $notification);

        $this->assertFalse($response->successful());
        $this->assertEquals(400, $response->status());
    }

    /** @test */
    public function it_handles_server_errors_with_retry()
    {
        Http::fake([
            'api.pushover.net/*' => Http::sequence()
                ->push('', 500)
                ->push('', 500)
                ->push('', 500)
                ->push(['status' => 1, 'request' => 'test-id'], 200)
        ]);

        $notification = new SystemAlert(
            AlertType::AMAZON_SESSION_EXPIRED,
            'Test message',
            ['test' => true]
        );

        $notifiable = new \stdClass();
        $response = $this->channel->send($notifiable, $notification);

        // Should succeed after retries
        $this->assertTrue($response->successful());

        // Should have made 4 requests (3 failures + 1 success)
        Http::assertSentCount(4);
    }

    /** @test */
    public function it_does_not_send_when_pushover_config_missing()
    {
        Http::fake();

        // Create a notification with empty configuration
        config(['services.pushover.token' => null, 'services.pushover.user' => null]);
        
        $notification = new SystemAlert(
            AlertType::AMAZON_SESSION_EXPIRED,
            'Test message',
            ['test' => true]
        );

        $notifiable = new \stdClass();
        $response = $this->channel->send($notifiable, $notification);

        // When pushover config is missing, no HTTP request should be made
        Http::assertNothingSent();
    }

    /** @test */
    public function it_logs_server_errors_after_retries()
    {
        Http::fake([
            'api.pushover.net/*' => Http::response('', 500)
        ]);

        $notification = new SystemAlert(
            AlertType::AMAZON_SESSION_EXPIRED,
            'Test message',
            ['test' => true]
        );

        $notifiable = new \stdClass();
        $response = $this->channel->send($notifiable, $notification);

        $this->assertFalse($response->successful());
        $this->assertEquals(500, $response->status());
    }

    /** @test */
    public function it_sends_proper_data_structure()
    {
        Http::fake([
            'api.pushover.net/*' => Http::response(['status' => 1], 200)
        ]);

        $notification = new SystemAlert(
            AlertType::AMAZON_SESSION_EXPIRED,
            'Test message',
            ['test' => true]
        );

        $notifiable = new \stdClass();
        $this->channel->send($notifiable, $notification);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['token']) && 
                   isset($data['user']) && 
                   isset($data['message']) && 
                   isset($data['title']) &&
                   isset($data['priority']);
        });
    }
} 