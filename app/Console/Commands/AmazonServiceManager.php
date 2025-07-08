<?php

namespace App\Console\Commands;

use App\Services\Amazon\AmazonReviewServiceFactory;
use App\Services\LoggingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class AmazonServiceManager extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amazon:service 
                            {action : Action to perform (status|switch|test|config)}
                            {--service= : Service type to switch to (unwrangle|scraping)}
                            {--asin= : ASIN to test with (defaults to B08N5WRWNW)}
                            {--show-config : Show current configuration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage Amazon review services (Unwrangle API vs Direct Scraping)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'status':
                return $this->showStatus();
            
            case 'switch':
                return $this->switchService();
            
            case 'test':
                return $this->testService();
            
            case 'config':
                return $this->showConfig();
            
            default:
                $this->error("Unknown action: {$action}");
                $this->info('Available actions: status, switch, test, config');
                return 1;
        }
    }

    /**
     * Show current service status.
     */
    private function showStatus(): int
    {
        $currentService = AmazonReviewServiceFactory::getCurrentServiceType();
        $availableServices = AmazonReviewServiceFactory::getAvailableServices();
        
        $this->info('=== Amazon Review Service Status ===');
        $this->line('');
        
        // Current service
        $this->info("Current Service: {$currentService}");
        
        if (isset($availableServices[$currentService])) {
            $serviceInfo = $availableServices[$currentService];
            $this->line("Description: {$serviceInfo['description']}");
            $this->line("Class: {$serviceInfo['class']}");
        }
        
        $this->line('');
        
        // Available services
        $this->info('Available Services:');
        foreach ($availableServices as $key => $service) {
            $status = $key === $currentService ? ' (ACTIVE)' : '';
            $this->line("  • {$key}: {$service['name']}{$status}");
            $this->line("    {$service['description']}");
        }
        
        $this->line('');
        
        // Configuration status
        $this->info('Configuration Status:');
        $this->checkConfiguration();
        
        return 0;
    }

    /**
     * Switch between services.
     */
    private function switchService(): int
    {
        $targetService = $this->option('service');
        
        if (!$targetService) {
            $this->error('Please specify a service to switch to using --service=');
            $this->info('Available services: unwrangle, scraping');
            return 1;
        }
        
        $availableServices = AmazonReviewServiceFactory::getAvailableServices();
        
        if (!isset($availableServices[$targetService])) {
            $this->error("Unknown service: {$targetService}");
            $this->info('Available services: ' . implode(', ', array_keys($availableServices)));
            return 1;
        }
        
        $currentService = AmazonReviewServiceFactory::getCurrentServiceType();
        
        if ($currentService === $targetService) {
            $this->info("Already using {$targetService} service");
            return 0;
        }
        
        $this->info("Switching from {$currentService} to {$targetService}...");
        
        // Show what needs to be configured
        $this->line('');
        $this->info('To complete the switch, update your .env file:');
        $this->line("AMAZON_REVIEW_SERVICE={$targetService}");
        
        if ($targetService === 'scraping') {
            $this->line('');
            $this->warn('Direct scraping also requires:');
            $this->line('AMAZON_COOKIES="session-id=your-session-id; session-token=your-token; ..."');
            $this->line('');
            $this->info('Cookie format: "name1=value1; name2=value2; name3=value3"');
            $this->info('You can get these from your browser\'s developer tools when logged into Amazon.');
        } elseif ($targetService === 'unwrangle') {
            $this->line('');
            $this->warn('Unwrangle API also requires:');
            $this->line('UNWRANGLE_API_KEY=your-api-key');
            $this->line('UNWRANGLE_AMAZON_COOKIE=your-amazon-cookie');
        }
        
        $this->line('');
        $this->info('After updating .env, run: php artisan amazon:service test');
        
        return 0;
    }

    /**
     * Test the current service configuration.
     */
    private function testService(): int
    {
        $asin = $this->option('asin') ?? 'B08N5WRWNW';
        $currentService = AmazonReviewServiceFactory::getCurrentServiceType();
        
        $this->info("Testing {$currentService} service with ASIN: {$asin}");
        $this->line('');
        
        try {
            $service = AmazonReviewServiceFactory::create();
            
            $this->info('Service created successfully');
            $this->line("Service type: " . get_class($service));
            
            $this->line('');
            $this->info('Testing fetchReviews method...');
            
            $startTime = microtime(true);
            $result = $service->fetchReviews($asin, 'us');
            $endTime = microtime(true);
            
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            if (!empty($result) && isset($result['reviews'])) {
                $this->info("✅ Success! Retrieved " . count($result['reviews']) . " reviews");
                $this->line("Duration: {$duration}ms");
                $this->line("Description: " . ($result['description'] ?? 'N/A'));
                $this->line("Total reviews: " . ($result['total_reviews'] ?? count($result['reviews'])));
                
                if (!empty($result['reviews'])) {
                    $this->line('');
                    $this->info('Sample review:');
                    $sample = $result['reviews'][0];
                    $this->line("Rating: " . ($sample['rating'] ?? 'N/A'));
                    $this->line("Author: " . ($sample['author'] ?? 'N/A'));
                    $this->line("Text: " . substr($sample['text'] ?? '', 0, 100) . '...');
                }
            } else {
                $this->error("❌ Failed to retrieve reviews");
                $this->line("Duration: {$duration}ms");
                $this->line("Result: " . json_encode($result));
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Test failed: " . $e->getMessage());
            $this->line("Exception: " . get_class($e));
            
            if ($this->option('verbose')) {
                $this->line("Stack trace:");
                $this->line($e->getTraceAsString());
            }
            
            return 1;
        }
        
        return 0;
    }

    /**
     * Show current configuration.
     */
    private function showConfig(): int
    {
        $this->info('=== Amazon Service Configuration ===');
        $this->line('');
        
        $currentService = AmazonReviewServiceFactory::getCurrentServiceType();
        $this->info("Current Service: {$currentService}");
        
        $this->line('');
        $this->info('Environment Variables:');
        
        // Common variables
        $this->showEnvVar('AMAZON_REVIEW_SERVICE', 'Service type selection');
        
        // Unwrangle-specific
        $this->line('');
        $this->info('Unwrangle API Configuration:');
        $this->showEnvVar('UNWRANGLE_API_KEY', 'API key for Unwrangle service');
        $this->showEnvVar('UNWRANGLE_AMAZON_COOKIE', 'Amazon cookie for Unwrangle');
        
        // Scraping-specific
        $this->line('');
        $this->info('Direct Scraping Configuration:');
        $this->showEnvVar('AMAZON_COOKIES', 'Amazon cookies for direct scraping');
        
        if ($this->option('show-config')) {
            $this->line('');
            $this->info('Full Configuration Values:');
            $this->line('AMAZON_REVIEW_SERVICE=' . env('AMAZON_REVIEW_SERVICE', 'unwrangle'));
            $this->line('UNWRANGLE_API_KEY=' . (env('UNWRANGLE_API_KEY') ? '[SET]' : '[NOT SET]'));
            $this->line('UNWRANGLE_AMAZON_COOKIE=' . (env('UNWRANGLE_AMAZON_COOKIE') ? '[SET]' : '[NOT SET]'));
            $this->line('AMAZON_COOKIES=' . (env('AMAZON_COOKIES') ? '[SET]' : '[NOT SET]'));
        }
        
        return 0;
    }

    /**
     * Show environment variable status.
     */
    private function showEnvVar(string $name, string $description): void
    {
        $value = env($name);
        $status = $value ? '✅ SET' : '❌ NOT SET';
        $this->line("  {$name}: {$status} - {$description}");
    }

    /**
     * Check configuration for current service.
     */
    private function checkConfiguration(): void
    {
        $currentService = AmazonReviewServiceFactory::getCurrentServiceType();
        
        if ($currentService === 'unwrangle') {
            $apiKey = env('UNWRANGLE_API_KEY');
            $cookie = env('UNWRANGLE_AMAZON_COOKIE');
            
            $this->line('  Unwrangle API Key: ' . ($apiKey ? '✅ SET' : '❌ NOT SET'));
            $this->line('  Amazon Cookie: ' . ($cookie ? '✅ SET' : '❌ NOT SET'));
            
            if (!$apiKey || !$cookie) {
                $this->warn('  ⚠️  Missing required configuration for Unwrangle API');
            }
        } elseif ($currentService === 'scraping') {
            $cookies = env('AMAZON_COOKIES');
            
            $this->line('  Amazon Cookies: ' . ($cookies ? '✅ SET' : '❌ NOT SET'));
            
            if (!$cookies) {
                $this->warn('  ⚠️  Missing required configuration for direct scraping');
            }
        }
    }
} 