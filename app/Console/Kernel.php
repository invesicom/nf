<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Generate sitemap daily to ensure Google has fresh product links
        $schedule->command('sitemap:generate --clear-cache')
                 ->daily()
                 ->at('02:00')
                 ->withoutOverlapping()
                 ->runInBackground();

        // Clean up old analysis sessions weekly
        $schedule->command('analysis:cleanup --hours=168') // 7 days
                 ->weekly()
                 ->sundays()
                 ->at('03:00');

        // Retry products with no reviews every hour
        $schedule->command('products:retry-no-reviews --limit=25 --age=24')
                 ->hourly()
                 ->withoutOverlapping()
                 ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
