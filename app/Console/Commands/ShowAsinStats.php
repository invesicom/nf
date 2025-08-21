<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShowAsinStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asin:stats 
                            {--detailed : Show detailed breakdown by country and date}
                            {--recent=7 : Show records from last N days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show statistics about ASIN records in the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $detailed = $this->option('detailed');
        $recentDays = (int) $this->option('recent');

        $this->info('📊 ASIN Database Statistics');
        $this->info('===========================');

        // Basic statistics
        $stats = $this->getBasicStats();

        $this->table(
            ['Metric', 'Count', 'Percentage'],
            [
                ['Total Records', $stats['total'], '100%'],
                ['Analyzed Records', $stats['analyzed'], $this->percentage($stats['analyzed'], $stats['total'])],
                ['Has Product Data', $stats['has_product_data'], $this->percentage($stats['has_product_data'], $stats['total'])],
                ['Needs Processing', $stats['needs_processing'], $this->percentage($stats['needs_processing'], $stats['total'])],
                ['Never Analyzed', $stats['never_analyzed'], $this->percentage($stats['never_analyzed'], $stats['total'])],
            ]
        );

        // Processing readiness
        $this->newLine();
        $this->info('🎯 Processing Readiness');
        $this->info('=======================');

        if ($stats['needs_processing'] > 0) {
            $this->line("✅ {$stats['needs_processing']} records are ready for product data scraping");
            $this->line('💡 Run: php artisan asin:process-existing --dry-run');
        } else {
            $this->line('🎉 All analyzed records already have product data!');
        }

        if ($stats['never_analyzed'] > 0) {
            $this->line("⏳ {$stats['never_analyzed']} records need review analysis first");
        }

        // Recent activity
        if ($recentDays > 0) {
            $this->newLine();
            $this->info("📅 Recent Activity (Last {$recentDays} days)");
            $this->info('=============================');

            $recentStats = $this->getRecentStats($recentDays);
            $this->table(
                ['Activity', 'Count'],
                [
                    ['New Records', $recentStats['new_records']],
                    ['Analyzed', $recentStats['analyzed']],
                    ['Product Data Added', $recentStats['product_data_added']],
                ]
            );
        }

        // Detailed breakdown
        if ($detailed) {
            $this->showDetailedStats();
        }

        // Sample records
        $this->newLine();
        $this->info('📋 Sample Records');
        $this->info('=================');

        $this->showSampleRecords();

        return Command::SUCCESS;
    }

    /**
     * Get basic statistics about ASIN records.
     */
    private function getBasicStats(): array
    {
        $total = AsinData::count();

        $analyzed = AsinData::whereNotNull('reviews')
                           ->whereNotNull('openai_result')
                           ->where('reviews', '!=', '[]')
                           ->where('openai_result', '!=', '[]')
                           ->count();

        $hasProductData = AsinData::where('have_product_data', true)->count();

        $needsProcessing = AsinData::where(function ($q) {
            $q->where('have_product_data', false)
              ->orWhereNull('have_product_data');
        })
                            ->whereNotNull('reviews')
                            ->whereNotNull('openai_result')
                            ->where('reviews', '!=', '[]')
                            ->where('openai_result', '!=', '[]')
                            ->count();

        $neverAnalyzed = AsinData::where(function ($q) {
            $q->whereNull('reviews')
              ->orWhereNull('openai_result')
              ->orWhere('reviews', '[]')
              ->orWhere('openai_result', '[]');
        })
                            ->count();

        return [
            'total'            => $total,
            'analyzed'         => $analyzed,
            'has_product_data' => $hasProductData,
            'needs_processing' => $needsProcessing,
            'never_analyzed'   => $neverAnalyzed,
        ];
    }

    /**
     * Get recent activity statistics.
     */
    private function getRecentStats(int $days): array
    {
        $since = now()->subDays($days);

        $newRecords = AsinData::where('created_at', '>=', $since)->count();

        $analyzed = AsinData::where('updated_at', '>=', $since)
                           ->whereNotNull('reviews')
                           ->whereNotNull('openai_result')
                           ->where('reviews', '!=', '[]')
                           ->where('openai_result', '!=', '[]')
                           ->count();

        $productDataAdded = AsinData::where('product_data_scraped_at', '>=', $since)
                                   ->where('have_product_data', true)
                                   ->count();

        return [
            'new_records'        => $newRecords,
            'analyzed'           => $analyzed,
            'product_data_added' => $productDataAdded,
        ];
    }

    /**
     * Show detailed statistics breakdown.
     */
    private function showDetailedStats(): void
    {
        $this->newLine();
        $this->info('📈 Detailed Breakdown');
        $this->info('=====================');

        // By country
        $byCountry = AsinData::select('country', DB::raw('count(*) as count'))
                            ->groupBy('country')
                            ->orderBy('count', 'desc')
                            ->get();

        if ($byCountry->count() > 0) {
            $this->line("\n🌍 By Country:");
            $countryData = $byCountry->map(function ($item) {
                return [
                    'Country'    => strtoupper($item->country),
                    'Count'      => $item->count,
                    'Percentage' => $this->percentage($item->count, AsinData::count()),
                ];
            })->toArray();

            $this->table(['Country', 'Count', 'Percentage'], $countryData);
        }

        // By grade distribution
        $this->line("\n📊 Grade Distribution:");
        $gradeStats = $this->getGradeStats();
        $this->table(['Grade', 'Count', 'Percentage'], $gradeStats);

        // Top products by fake percentage
        $this->line("\n🔥 Highest Fake Review Percentages:");
        $topFake = AsinData::whereNotNull('openai_result')
                          ->where('have_product_data', true)
                          ->get()
                          ->sortByDesc('fake_percentage')
                          ->take(5);

        $topFakeData = $topFake->map(function ($item) {
            return [
                'ASIN'   => $item->asin,
                'Title'  => \Str::limit($item->product_title ?? 'N/A', 40),
                'Fake %' => $item->fake_percentage.'%',
                'Grade'  => $item->grade,
            ];
        })->toArray();

        if (!empty($topFakeData)) {
            $this->table(['ASIN', 'Title', 'Fake %', 'Grade'], $topFakeData);
        }
    }

    /**
     * Get grade distribution statistics.
     */
    private function getGradeStats(): array
    {
        $analyzed = AsinData::whereNotNull('openai_result')
                           ->where('openai_result', '!=', '[]')
                           ->get();

        $grades = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
        $total = $analyzed->count();

        foreach ($analyzed as $record) {
            $grade = $record->grade;
            if (isset($grades[$grade])) {
                $grades[$grade]++;
            }
        }

        return collect($grades)->map(function ($count, $grade) use ($total) {
            return [
                'Grade'      => $grade,
                'Count'      => $count,
                'Percentage' => $this->percentage($count, $total),
            ];
        })->values()->toArray();
    }

    /**
     * Show sample records for different categories.
     */
    private function showSampleRecords(): void
    {
        // Sample of records that need processing
        $needsProcessing = AsinData::where(function ($q) {
            $q->where('have_product_data', false)
              ->orWhereNull('have_product_data');
        })
                            ->whereNotNull('reviews')
                            ->whereNotNull('openai_result')
                            ->where('reviews', '!=', '[]')
                            ->where('openai_result', '!=', '[]')
                            ->take(3)
                            ->get();

        if ($needsProcessing->count() > 0) {
            $this->line("\n🔄 Sample records needing processing:");
            $sampleData = $needsProcessing->map(function ($item) {
                return [
                    'ASIN'    => $item->asin,
                    'Country' => strtoupper($item->country),
                    'Reviews' => count($item->getReviewsArray()),
                    'Grade'   => $item->grade ?? 'N/A',
                    'Created' => $item->created_at->format('M j, Y'),
                ];
            })->toArray();

            $this->table(['ASIN', 'Country', 'Reviews', 'Grade', 'Created'], $sampleData);
        }

        // Sample of processed records
        $processed = AsinData::where('have_product_data', true)
                            ->whereNotNull('product_title')
                            ->take(3)
                            ->get();

        if ($processed->count() > 0) {
            $this->line("\n✅ Sample processed records:");
            $processedData = $processed->map(function ($item) {
                return [
                    'ASIN'    => $item->asin,
                    'Title'   => \Str::limit($item->product_title, 30),
                    'Grade'   => $item->grade,
                    'Fake %'  => $item->fake_percentage.'%',
                    'SEO URL' => $item->seo_url,
                ];
            })->toArray();

            $this->table(['ASIN', 'Title', 'Grade', 'Fake %', 'SEO URL'], $processedData);
        }
    }

    /**
     * Calculate percentage with proper formatting.
     */
    private function percentage(int $part, int $total): string
    {
        if ($total === 0) {
            return '0%';
        }

        return round(($part / $total) * 100, 1).'%';
    }
}
