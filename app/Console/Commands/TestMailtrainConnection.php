<?php

namespace App\Console\Commands;

use App\Services\NewsletterService;
use Illuminate\Console\Command;

class TestMailtrainConnection extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mailtrain:test {--email= : Test email to subscribe/unsubscribe}';

    /**
     * The console command description.
     */
    protected $description = 'Test Mailtrain connection and API functionality';

    /**
     * Execute the console command.
     */
    public function handle(NewsletterService $newsletterService): int
    {
        $this->info('🧪 Testing Mailtrain Connection...');
        $this->newLine();

        // Test basic connection
        $this->info('1. Testing API connection...');
        $connectionTest = $newsletterService->testConnection();

        if ($connectionTest['success']) {
            $this->info('✅ Connection successful!');
            $this->line('   Base URL: '.config('services.mailtrain.base_url'));
            $this->line('   List ID: '.config('services.mailtrain.list_id'));
        } else {
            $this->error('❌ Connection failed: '.$connectionTest['message']);

            return 1;
        }

        $this->newLine();

        // Test email subscription if provided
        $testEmail = $this->option('email');
        if ($testEmail) {
            $this->info('2. Testing subscription for: '.$testEmail);

            $subscribeResult = $newsletterService->subscribe($testEmail);

            if ($subscribeResult['success']) {
                $this->info('✅ Subscription successful!');
                $this->line('   Message: '.$subscribeResult['message']);

                // Test checking subscription status
                $this->newLine();
                $this->info('3. Testing subscription status check...');

                $statusResult = $newsletterService->checkSubscription($testEmail);
                if ($statusResult['success']) {
                    $subscribed = $statusResult['subscribed'] ? 'Yes' : 'No';
                    $this->info('✅ Status check successful!');
                    $this->line('   Subscribed: '.$subscribed);
                } else {
                    $this->warn('⚠️  Status check failed: '.($statusResult['error'] ?? 'Unknown error'));
                }
            } else {
                $this->error('❌ Subscription failed: '.$subscribeResult['message']);
                if (isset($subscribeResult['code']) && $subscribeResult['code'] === 'ALREADY_SUBSCRIBED') {
                    $this->line('   (This is normal if the email is already subscribed)');
                }
            }
        } else {
            $this->line('💡 Use --email=test@example.com to test subscription functionality');
        }

        $this->newLine();
        $this->info('🎉 Mailtrain test completed!');

        return 0;
    }
}
