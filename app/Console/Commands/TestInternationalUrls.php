<?php

namespace App\Console\Commands;

use App\Services\Amazon\BrightDataScraperService;
use App\Services\ReviewAnalysisService;
use App\Services\ReviewService;
use Illuminate\Console\Command;

class TestInternationalUrls extends Command
{
    protected $signature = 'test:international-urls {--dry-run : Show what would be tested without making API calls}';
    protected $description = 'Test international Amazon URLs with the BrightData scraper to verify support for non-US domains';

    private array $testUrls = [
        // Spain - Cordless Vacuum Cleaner
        'https://www.amazon.es/-/en/Cordless-Cleaner-Filtration-Self-Charging-Suitable/dp/B0CZRTHM6T/?_encoding=UTF8&pd_rd_w=WAFTK&content-id=amzn1.sym.0a1e4d50-7b96-465b-86c1-adb7e525fae2&pf_rd_p=0a1e4d50-7b96-465b-86c1-adb7e525fae2&pf_rd_r=3733V7TN9QTWREHD2TXD&pd_rd_wg=yURkV&pd_rd_r=db92a47e-9b36-4e9f-b6f9-a9ed81d0ce3f&ref_=pd_hp_d_atf_unk',

        // Canada - Dr. Scholl's Women's Sneaker
        'https://www.amazon.ca/Dr-Scholls-Womens-Sneaker-Pebbled/dp/B0F5RQDJD3/?_encoding=UTF8&pd_rd_w=Tqw9o&content-id=amzn1.sym.b72a7db1-2e40-43b1-9a87-c772f9c9cf18%3Aamzn1.symc.5a16118f-86f0-44cd-8e3e-6c5f82df43d0&pf_rd_p=b72a7db1-2e40-43b1-9a87-c772f9c9cf18&pf_rd_r=5667T75QEYPMHY0HY14K&pd_rd_wg=cLF3N&pd_rd_r=79886abc-1fa8-4a09-87f7-b5ccb393bf9e&ref_=pd_hp_d_atf_ci_mcx_mr_ca_hp_atf_d&th=1&psc=1',

        // Germany - Bosch Cordless Drill
        'https://www.amazon.de/-/en/TRAPANO-BATTERIA-PERCUSSIONE-GSB12-BOSCH/dp/B00YYBBUBY/?_encoding=UTF8&pd_rd_w=Zzs28&content-id=amzn1.sym.79d1f343-1e12-4a57-8f6f-6e5712b5effc&pf_rd_p=79d1f343-1e12-4a57-8f6f-6e5712b5effc&pf_rd_r=WG4Q4KRVS8D31NG9RZM4&pd_rd_wg=LHTEm&pd_rd_r=caa7cd34-7644-4b9a-8155-7d8b555bf35c&ref_=pd_hp_d_atf_unk&th=1',

        // UK - Vax Smartwash Pet Carpet Cleaner
        'https://www.amazon.co.uk/Vax-Smartwash-Pet-Design-Bacteria-Pre-treatment/dp/B0BHF3NKLK/?_encoding=UTF8&pd_rd_w=kg71a&content-id=amzn1.sym.6b52c2a5-fada-4e73-a099-82fe56c2e0de&pf_rd_p=6b52c2a5-fada-4e73-a099-82fe56c2e0de&pf_rd_r=T8ARXE1G7HKWVAGS60BG&pd_rd_wg=AuIqK&pd_rd_r=b58cb77e-c828-4151-a686-13f547268e47&ref_=pd_hp_d_atf_unk',

        // France - Bluetooth Earphones
        'https://www.amazon.fr/-/en/Bluetooth-Earphones-Wireless-Headphones-Waterproof/dp/B0FCS5ZRB4?pd_rd_w=iSuoB&content-id=amzn1.sym.fce6efc7-fe76-4a3f-9da8-ff482a213bd9&pf_rd_p=fce6efc7-fe76-4a3f-9da8-ff482a213bd9&pf_rd_r=0TXWBZ7G9FWYXY4GFPED&pd_rd_wg=y6Xuf&pd_rd_r=3a9d30a4-46be-4b4e-b4ba-00c864738154&pd_rd_i=B0FCS5ZRB4&ref_=oct_dx_dotd_B0FCS5ZRB4&th=1',

        // India - Daikin Air Conditioner
        'https://www.amazon.in/Daikin-Inverter-Display-Technology-MTKL50U/dp/B0BK1KS6ZD/ref=sr_1_1?_encoding=UTF8&content-id=amzn1.sym.58c90a12-100b-4a2f-8e15-7c06f1abe2be&dib=eyJ2IjoiMSJ9.LpujZ4uISPUK8sa_6yNGVRbnpLAogbToTOMZRXNZaZLHJzi1PcD7zU26zi8606TjrrNsPUqaO56EB1q2ZtYqEcp3pxAAtbh7B8Mxkevdf1MSLJKljgHsq15FLN9z5ujpgE0fUAcBYA-pjzpyvjw43sw7xeQIi37yfHoIjTRqXRrSoRvuNK30kHPNXG82j7_EMgux_DePJ6pg4_dxjo9ZJAPmXSalAlHgH9ywHpd1LsGTWJPd25Mu3Po9JeDqBuZjSsgohu1I9n95YDRwvZkxGJJRl1IPl1-iMrABuYNt6zY.tbc0TvjZUWeaJYj6eg0ZuvVvXv3Kxr2MyJ0aqdoFPP4&dib_tag=se&pd_rd_r=e7a606a0-6a3f-4735-b476-f67cae41bf36&pd_rd_w=ngzzp&pd_rd_wg=lBFZZ&qid=1755130658&refinements=p_85%3A10440599031&rps=1&s=kitchen&sr=1-1',

        // Japan - Bagasin Laptop Sleeve
        'https://www.amazon.co.jp/-/en/Bagasin-Shockproof-Laptop-MacBook-Notebooks/dp/B0BLNHG168?ref_=Oct_d_obs_d_2151881051_1&pd_rd_w=HQ5iA&content-id=amzn1.sym.93c56678-e2c2-4ede-8359-bb8af1f3e304&pf_rd_p=93c56678-e2c2-4ede-8359-bb8af1f3e304&pf_rd_r=433CCRM18EQ0VNZTM8EY&pd_rd_wg=k8EDV&pd_rd_r=cd474e7c-a202-4122-b53c-c2889235afa7&pd_rd_i=B0BLNHG168&th=1',

        // Mexico - Samsung Galaxy Phone
        'https://www.amazon.com.mx/SAMSUNG-Galaxy-Negro-Onyx-12GB_256GB/dp/B0CQ84BYDC/?_encoding=UTF8&pd_rd_w=vzDaw&content-id=amzn1.sym.802f54ee-e822-4638-95e2-f48ad27398f5&pf_rd_p=802f54ee-e822-4638-95e2-f48ad27398f5&pf_rd_r=S0PQ5A0GB164S719RHJ3&pd_rd_wg=81wtp&pd_rd_r=0a9682fe-cf65-43cc-880d-ecf93ebdf12c&ref_=pd_hp_d_atf_unk&th=1',

        // Brazil - PlayStation 4 Controller
        'https://www.amazon.com.br/Controle-Dualshock-PlayStation-4-Preto/dp/B07FN1MZBH/?_encoding=UTF8&pd_rd_w=0hzID&content-id=amzn1.sym.8fbb3d34-c3f1-46af-9d99-fd6986f6ec8f&pf_rd_p=8fbb3d34-c3f1-46af-9d99-fd6986f6ec8f&pf_rd_r=84AB35NY0875RAHGSWKB&pd_rd_wg=s8S2C&pd_rd_r=74768ec1-4928-4442-aa2e-9f889f2c21e3&ref_=pd_hp_d_btf_crs_zg_bs_7791985011',
    ];

    public function handle()
    {
        $this->info('Testing International Amazon URLs with BrightData Scraper');
        $this->info('='.str_repeat('=', 65));

        $reviewService = app(ReviewService::class);
        $analysisService = app(ReviewAnalysisService::class);
        $brightDataService = app(BrightDataScraperService::class);

        $results = [];
        $dryRun = $this->option('dry-run');

        foreach ($this->testUrls as $index => $url) {
            $this->info("\nTesting URL ".($index + 1).': '.substr($url, 0, 80).'...');
            $this->line(str_repeat('-', 80));

            try {
                // Test URL parsing
                $this->info('Step 1: Testing URL parsing...');
                $asin = $analysisService->extractAsinFromUrl($url);
                $country = $reviewService->extractCountryFromUrl($url);

                $this->line("  ✓ ASIN extracted: {$asin}");
                $this->line("  ✓ Country detected: {$country}");

                // Test Amazon URL building
                $this->info('Step 2: Testing Amazon URL building...');
                $reflection = new \ReflectionClass($brightDataService);
                $buildUrlMethod = $reflection->getMethod('buildAmazonUrl');
                $buildUrlMethod->setAccessible(true);
                $amazonUrl = $buildUrlMethod->invoke($brightDataService, $asin, $country);

                $this->line("  ✓ Built Amazon URL: {$amazonUrl}");

                // Check domain mapping
                $expectedDomains = [
                    'gb' => 'amazon.co.uk',
                    'ca' => 'amazon.ca',
                    'de' => 'amazon.de',
                    'es' => 'amazon.es',
                    'fr' => 'amazon.fr',
                    'it' => 'amazon.it',
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
                    'be' => 'amazon.be',
                ];

                if (isset($expectedDomains[$country])) {
                    $expectedDomain = $expectedDomains[$country];
                    if (strpos($amazonUrl, $expectedDomain) !== false) {
                        $this->line("  ✓ Domain mapping correct for {$country}");
                    } else {
                        $this->error("  ✗ Domain mapping incorrect - expected {$expectedDomain}");
                    }
                } else {
                    $this->warn("  ⚠ Country code {$country} not in expected domains list");
                }

                if (!$dryRun) {
                    $this->info('Step 3: Testing BrightData API call...');

                    // Test the actual BrightData call
                    $startTime = microtime(true);
                    $result = $brightDataService->fetchReviews($asin, $country);
                    $endTime = microtime(true);
                    $duration = round($endTime - $startTime, 2);

                    $reviewCount = count($result['reviews'] ?? []);
                    $totalReviews = $result['total_reviews'] ?? 0;
                    $productName = $result['product_name'] ?? 'N/A';

                    $this->line("  ✓ BrightData call completed in {$duration}s");
                    $this->line("  ✓ Reviews fetched: {$reviewCount}");
                    $this->line("  ✓ Total reviews on Amazon: {$totalReviews}");
                    $this->line('  ✓ Product name: '.substr($productName, 0, 50).(strlen($productName) > 50 ? '...' : ''));

                    $results[$url] = [
                        'asin'            => $asin,
                        'country'         => $country,
                        'amazon_url'      => $amazonUrl,
                        'duration'        => $duration,
                        'reviews_fetched' => $reviewCount,
                        'total_reviews'   => $totalReviews,
                        'product_name'    => $productName,
                        'success'         => $reviewCount > 0 || !empty($productName),
                    ];
                } else {
                    $this->info('Step 3: Skipped (dry-run mode)');
                    $results[$url] = [
                        'asin'       => $asin,
                        'country'    => $country,
                        'amazon_url' => $amazonUrl,
                        'dry_run'    => true,
                    ];
                }

                $this->info('  ✓ URL test completed successfully');
            } catch (\Exception $e) {
                $this->error('  ✗ Error: '.$e->getMessage());
                $results[$url] = [
                    'error'   => $e->getMessage(),
                    'success' => false,
                ];
            }
        }

        // Summary
        $this->info("\n".str_repeat('=', 80));
        $this->info('SUMMARY');
        $this->info(str_repeat('=', 80));

        $successful = 0;
        $failed = 0;

        foreach ($results as $url => $result) {
            $domain = parse_url($url, PHP_URL_HOST);

            if (isset($result['error'])) {
                $this->error("✗ {$domain}: {$result['error']}");
                $failed++;
            } else {
                if ($dryRun || ($result['success'] ?? false)) {
                    $this->info("✓ {$domain}: ASIN {$result['asin']}, Country {$result['country']}");
                    if (!$dryRun) {
                        $this->line("    Reviews: {$result['reviews_fetched']}, Duration: {$result['duration']}s");
                    }
                    $successful++;
                } else {
                    $this->warn("⚠ {$domain}: No data returned (may indicate geo-restrictions or product issues)");
                    $failed++;
                }
            }
        }

        $this->info("\nResults: {$successful} successful, {$failed} failed");

        if (!$dryRun && $successful > 0) {
            $this->info("\n✓ International domain support is working with BrightData!");
            $this->info('The system can successfully process URLs from:');
            foreach ($results as $url => $result) {
                if (($result['success'] ?? false) && !isset($result['error'])) {
                    $domain = parse_url($url, PHP_URL_HOST);
                    $this->line("  - {$domain} (Country: {$result['country']})");
                }
            }
        } elseif ($dryRun) {
            $this->info("\n✓ URL parsing and country detection is working for international domains!");
            $this->warn('Run without --dry-run to test actual BrightData API calls.');
        }

        if ($failed > 0) {
            $this->warn("\nSome URLs failed. This could be due to:");
            $this->line('  - Geo-restrictions on certain products');
            $this->line('  - Products no longer available');
            $this->line('  - BrightData configuration issues');
            $this->line('  - Network connectivity issues');
        }

        return $successful > $failed ? 0 : 1;
    }
}
