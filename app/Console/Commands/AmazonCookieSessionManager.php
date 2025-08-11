<?php

namespace App\Console\Commands;

use App\Services\Amazon\CookieSessionManager;
use Illuminate\Console\Command;

class AmazonCookieSessionManager extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amazon:cookie-sessions 
                            {action? : Action to perform (list|reset-health|info)}
                            {--session= : Specific session index to operate on}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage Amazon cookie sessions for multi-session rotation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action') ?? 'list';
        $cookieSessionManager = new CookieSessionManager();

        switch ($action) {
            case 'list':
                return $this->listSessions($cookieSessionManager);
            
            case 'info':
                return $this->showDetailedInfo($cookieSessionManager);
            
            case 'reset-health':
                return $this->resetSessionHealth($cookieSessionManager);
            
            default:
                $this->error("Unknown action: {$action}");
                $this->line("Available actions: list, info, reset-health");
                return 1;
        }
    }

    /**
     * List all available cookie sessions.
     */
    private function listSessions(CookieSessionManager $manager): int
    {
        $sessionInfo = $manager->getSessionInfo();
        
        if (empty($sessionInfo)) {
            $this->warn('No Amazon cookie sessions configured.');
            $this->line('Configure sessions using environment variables AMAZON_COOKIES_1 through AMAZON_COOKIES_10');
            return 0;
        }

        $this->info("Amazon Cookie Sessions ({$manager->getSessionCount()} configured):");
        $this->line('');

        $headers = ['Index', 'Name', 'Environment Variable', 'Cookies', 'Status', 'Current'];
        $rows = [];

        foreach ($sessionInfo as $info) {
            $status = $info['is_healthy'] ? '<info>Healthy</info>' : '<error>Unhealthy</error>';
            if (!$info['is_healthy'] && isset($info['health_info'])) {
                $status .= "\n({$info['health_info']['reason']})";
            }
            
            $rows[] = [
                $info['index'],
                $info['name'],
                $info['env_var'],
                $info['has_cookies'] ? $info['cookie_count'] : '<error>No cookies</error>',
                $status,
                $info['is_current'] ? '<comment>Yes</comment>' : 'No'
            ];
        }

        $this->table($headers, $rows);
        return 0;
    }

    /**
     * Show detailed information about sessions.
     */
    private function showDetailedInfo(CookieSessionManager $manager): int
    {
        $sessionInfo = $manager->getSessionInfo();
        
        if (empty($sessionInfo)) {
            $this->warn('No Amazon cookie sessions configured.');
            return 0;
        }

        $this->info("Detailed Amazon Cookie Session Information:");
        $this->line('');

        foreach ($sessionInfo as $info) {
            $this->line("<comment>{$info['name']} ({$info['env_var']})</comment>");
            $this->line("  Index: {$info['index']}");
            $this->line("  Has Cookies: " . ($info['has_cookies'] ? 'Yes' : 'No'));
            $this->line("  Cookie Count: {$info['cookie_count']}");
            $this->line("  Status: " . ($info['is_healthy'] ? 'Healthy' : 'Unhealthy'));
            $this->line("  Is Current: " . ($info['is_current'] ? 'Yes' : 'No'));
            
            if (!$info['is_healthy'] && isset($info['health_info'])) {
                $health = $info['health_info'];
                $this->line("  Marked Unhealthy: {$health['marked_at']}");
                $this->line("  Reason: {$health['reason']}");
                $this->line("  Expires: {$health['expires_at']}");
            }
            
            $this->line('');
        }

        return 0;
    }

    /**
     * Reset session health status.
     */
    private function resetSessionHealth(CookieSessionManager $manager): int
    {
        $sessionIndex = $this->option('session');
        
        if ($sessionIndex) {
            // Reset specific session
            $session = $manager->getSessionByIndex((int)$sessionIndex);
            if (!$session) {
                $this->error("Session {$sessionIndex} not found.");
                return 1;
            }
            
            $manager->markSessionUnhealthy((int)$sessionIndex, 'Manual reset', 0); // 0 minutes = immediate reset
            $this->info("Reset health status for {$session['name']}");
        } else {
            // Reset all sessions
            if (!$this->confirm('Are you sure you want to reset health status for ALL sessions?')) {
                $this->line('Cancelled.');
                return 0;
            }
            
            $manager->resetAllSessionHealth();
            $this->info('Reset health status for all sessions.');
        }

        return 0;
    }
}
