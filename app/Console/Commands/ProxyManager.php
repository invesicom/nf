<?php

namespace App\Console\Commands;

use App\Services\Amazon\AmazonScrapingService;
use App\Services\Amazon\ProxyManager as ProxyManagerService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class ProxyManager extends Command
{
    protected $signature = 'proxy:manage {action} {--provider=} {--test-asin=B0CBC67ZXC}';

    protected $description = 'Manage proxy configuration for Amazon scraping';

    public function handle()
    {
        $action = $this->argument('action');
        $provider = $this->option('provider');
        $testAsin = $this->option('test-asin');

        switch ($action) {
            case 'status':
                $this->showProxyStatus();
                break;
            case 'test':
                $this->testProxies($provider, $testAsin);
                break;
            case 'rotate':
                $this->rotateProxies();
                break;
            case 'setup':
                $this->setupProxyEnvironment();
                break;
            default:
                $this->error("Unknown action: {$action}");
                $this->showHelp();
        }
    }

    private function showProxyStatus()
    {
        $this->info('Proxy System Status');
        $this->newLine();

        $proxyManager = new ProxyManagerService();
        $stats = $proxyManager->getProxyStats();

        $this->line('Third-party Providers: '.$stats['providers']);
        $this->line('Custom Proxies: '.$stats['custom_proxies']);
        $this->line('Active Provider: '.($stats['active_provider'] ?: 'None'));

        if (isset($stats['custom_success_rate'])) {
            $successRate = round($stats['custom_success_rate'] * 100, 2);
            $this->line("Custom Proxy Success Rate: {$successRate}%");
        }

        $this->newLine();
        $this->info('Environment Configuration:');

        $envVars = [
            'BRIGHTDATA_USERNAME' => 'Bright Data Username',
            'OXYLABS_USERNAME'    => 'Oxylabs Username',
            'SMARTPROXY_USERNAME' => 'Smartproxy Username',
            'PROXYMESH_USERNAME'  => 'ProxyMesh Username',
            'CUSTOM_PROXIES'      => 'Custom Proxy List',
        ];

        foreach ($envVars as $var => $description) {
            $value = env($var);
            $status = $value ? '[CONFIGURED]' : '[NOT SET]';
            $this->line("{$description}: {$status}");
        }
    }

    private function testProxies(?string $provider, string $testAsin)
    {
        $this->info('Testing Proxy Configuration');
        $this->newLine();

        if ($provider) {
            $this->testSpecificProvider($provider, $testAsin);
        } else {
            $this->testAllProxies($testAsin);
        }
    }

    private function testSpecificProvider(string $provider, string $testAsin)
    {
        $this->line("Testing provider: {$provider}");

        // Test basic connectivity
        $this->testProxyConnectivity($provider);

        // Test with Amazon scraping
        $this->testAmazonScraping($testAsin);
    }

    private function testAllProxies(string $testAsin)
    {
        $this->line('Testing all available proxies...');

        $proxyManager = new ProxyManagerService();
        $proxyConfig = $proxyManager->getProxyConfig();

        if (!$proxyConfig) {
            $this->error('No proxy configuration available');

            return;
        }

        $this->line('Selected proxy: '.$proxyConfig['type'].' ('.($proxyConfig['provider'] ?? 'custom').')');

        // Test basic connectivity
        $this->testBasicConnectivity($proxyConfig);

        // Test Amazon scraping
        $this->testAmazonScraping($testAsin);
    }

    private function testBasicConnectivity(array $proxyConfig)
    {
        $this->line('Testing basic connectivity...');

        try {
            $client = new Client([
                'proxy'   => $proxyConfig['proxy'],
                'timeout' => 10,
                'verify'  => false,
            ]);

            $response = $client->get('https://httpbin.org/ip');
            $data = json_decode($response->getBody()->getContents(), true);

            $this->line('SUCCESS - Proxy IP: '.$data['origin']);
        } catch (\Exception $e) {
            $this->error('FAILED - Connectivity test failed: '.$e->getMessage());
        }
    }

    private function testProxyConnectivity(string $provider)
    {
        $this->line("Testing basic connectivity for {$provider}...");

        $endpoints = [
            'brightdata' => 'http://httpbin.org/ip',
            'oxylabs'    => 'http://httpbin.org/ip',
            'smartproxy' => 'http://httpbin.org/ip',
            'proxymesh'  => 'http://httpbin.org/ip',
        ];

        if (!isset($endpoints[$provider])) {
            $this->error("Unknown provider: {$provider}");

            return;
        }

        try {
            $username = env(strtoupper($provider).'_USERNAME');
            $password = env(strtoupper($provider).'_PASSWORD');
            $endpoint = env(strtoupper($provider).'_ENDPOINT');

            if (!$username || !$password) {
                $this->error("Missing credentials for {$provider}");

                return;
            }

            $proxyUrl = "http://{$username}:{$password}@{$endpoint}";

            $client = new Client([
                'proxy'   => $proxyUrl,
                'timeout' => 10,
                'verify'  => false,
            ]);

            $response = $client->get($endpoints[$provider]);
            $data = json_decode($response->getBody()->getContents(), true);

            $this->line("SUCCESS - {$provider} IP: ".$data['origin']);
        } catch (\Exception $e) {
            $this->error("FAILED - {$provider} test failed: ".$e->getMessage());
        }
    }

    private function testAmazonScraping(string $testAsin)
    {
        $this->line('Testing Amazon scraping with proxy...');

        try {
            $scrapingService = new AmazonScrapingService();
            $reviewsData = $scrapingService->fetchReviews($testAsin);

            $reviewCount = count($reviewsData['reviews'] ?? []);

            if ($reviewCount > 0) {
                $this->line("SUCCESS - Successfully scraped {$reviewCount} reviews");

                // Show proxy stats
                $stats = $scrapingService->getProxyStats();
                $this->line('Proxy stats: '.json_encode($stats));
            } else {
                $this->warn('WARNING - Scraping returned 0 reviews - may indicate blocking');
            }
        } catch (\Exception $e) {
            $this->error('FAILED - Amazon scraping test failed: '.$e->getMessage());
        }
    }

    private function rotateProxies()
    {
        $this->info('Rotating proxy configuration...');

        $proxyManager = new ProxyManagerService();
        $proxyManager->rotateSession();

        $this->line('SUCCESS - Proxy session rotated');
    }

    private function setupProxyEnvironment()
    {
        $this->info('Proxy Environment Setup Guide');
        $this->newLine();

        $this->line('Add these environment variables to your .env file:');
        $this->newLine();

        $this->line('# Third-party Proxy Providers (choose one or more)');
        $this->line('# Bright Data (Premium residential proxies)');
        $this->line('BRIGHTDATA_USERNAME=your_username');
        $this->line('BRIGHTDATA_PASSWORD=your_password');
        $this->line('BRIGHTDATA_ENDPOINT=brd-customer-hl_USERNAME-zone-static.brd.superproxy.io:22225');
        $this->newLine();

        $this->line('# Oxylabs (Residential proxies)');
        $this->line('OXYLABS_USERNAME=your_username');
        $this->line('OXYLABS_PASSWORD=your_password');
        $this->line('OXYLABS_ENDPOINT=pr.oxylabs.io:7777');
        $this->newLine();

        $this->line('# Smartproxy (Affordable residential)');
        $this->line('SMARTPROXY_USERNAME=your_username');
        $this->line('SMARTPROXY_PASSWORD=your_password');
        $this->line('SMARTPROXY_ENDPOINT=gate.smartproxy.com:7000');
        $this->newLine();

        $this->line('# Custom Proxy Network');
        $this->line('# Format: ip1:port1:user1:pass1:country1,ip2:port2:user2:pass2:country2');
        $this->line('CUSTOM_PROXIES=1.2.3.4:8080:user1:pass1:US,5.6.7.8:3128:user2:pass2:UK');
        $this->newLine();

        $this->info('Recommended Setup:');
        $this->line('1. Start with Smartproxy for cost-effective residential proxies');
        $this->line('2. Use Bright Data for maximum reliability');
        $this->line('3. Build custom proxy network for scale');
        $this->newLine();

        $this->info('Building Your Own Proxy Network:');
        $this->line('1. Set up VPS servers worldwide (AWS, DigitalOcean, Vultr)');
        $this->line('2. Install Squid proxy on each server');
        $this->line('3. Configure authentication');
        $this->line('4. Add to CUSTOM_PROXIES environment variable');
    }

    private function showHelp()
    {
        $this->newLine();
        $this->line('Available actions:');
        $this->line('  status  - Show proxy system status');
        $this->line('  test    - Test proxy configuration');
        $this->line('  rotate  - Rotate proxy sessions');
        $this->line('  setup   - Show setup instructions');
        $this->newLine();
        $this->line('Examples:');
        $this->line('  php artisan proxy:manage status');
        $this->line('  php artisan proxy:manage test --provider=brightdata');
        $this->line('  php artisan proxy:manage test --test-asin=B0CBC67ZXC');
    }
}
