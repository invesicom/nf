<?php

namespace App\Console\Commands;

use App\Services\Amazon\BrightDataScraperService;
use Illuminate\Console\Command;

class CheckBrightDataProgress extends Command
{
    protected $signature = 'brightdata:progress';
    protected $description = 'Check current BrightData scraping progress';

    public function handle()
    {
        $this->info('🔍 Checking BrightData Progress');
        $this->line('='.str_repeat('=', 40));

        $apiKey = env('BRIGHTDATA_SCRAPER_API');
        if (empty($apiKey)) {
            $this->error('❌ BRIGHTDATA_SCRAPER_API not configured');

            return 1;
        }

        try {
            $service = app()->make(BrightDataScraperService::class);
            $progress = $service->checkProgress();

            if (empty($progress)) {
                $this->info('✅ No active scraping jobs');

                return 0;
            }

            $this->info('📊 Active Jobs: '.count($progress));
            $this->line('');

            foreach ($progress as $i => $job) {
                $num = $i + 1;
                $this->info("🔄 Job #{$num}:");
                $this->info('   ID: '.($job['snapshot_id'] ?? 'Unknown'));
                $this->info('   Status: '.($job['status'] ?? 'Unknown'));
                $this->info('   Created: '.($job['created_at'] ?? 'Unknown'));
                $this->info('   Rows: '.($job['total_rows'] ?? 0));

                if (isset($job['dataset_id'])) {
                    $this->info('   Dataset: '.$job['dataset_id']);
                }

                if (isset($job['progress_percentage'])) {
                    $this->info('   Progress: '.$job['progress_percentage'].'%');
                }

                $this->line('');
            }

            $this->info('🏁 Progress check completed');

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to check progress: '.$e->getMessage());

            return 1;
        }
    }
}
