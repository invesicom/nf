<?php

namespace App\Console\Commands;

use App\Models\AnalysisSession;
use Illuminate\Console\Command;

class CleanupAnalysisSessions extends Command
{
    protected $signature = 'analysis:cleanup 
                           {--hours=24 : Delete sessions older than this many hours}
                           {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Clean up old analysis sessions';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');

        $this->info('🧹 Analysis Session Cleanup');
        $this->info('==========================');

        $cutoff = now()->subHours($hours);
        
        $query = AnalysisSession::where('created_at', '<', $cutoff);
        
        $totalSessions = $query->count();
        
        if ($totalSessions === 0) {
            $this->info('✅ No sessions found older than ' . $hours . ' hours');
            return 0;
        }

        // Show breakdown by status
        $breakdown = AnalysisSession::where('created_at', '<', $cutoff)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get();

        $this->info("📊 Found {$totalSessions} sessions older than {$hours} hours:");
        foreach ($breakdown as $item) {
            $this->info("   • {$item->status}: {$item->count}");
        }

        if ($dryRun) {
            $this->warn('🧪 DRY RUN MODE: No sessions will be deleted');
            return 0;
        }

        if (!$this->confirm('Do you want to delete these sessions?')) {
            $this->info('❌ Cleanup cancelled');
            return 0;
        }

        $deleted = $query->delete();

        $this->info("✅ Deleted {$deleted} analysis sessions");
        
        return 0;
    }
} 