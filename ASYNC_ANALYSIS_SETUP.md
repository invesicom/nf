# Async Analysis System Setup

This document describes the new asynchronous analysis system designed to solve Cloudflare timeout issues while maintaining the exact same user experience.

## 🎯 Problem Solved

**Issue**: Cloudflare Pro times out long-running requests (analysis can take 60+ seconds)
**Solution**: Queue-based async processing with real-time progress polling

## 🏗️ Architecture Overview

### Components

1. **AnalysisSession Model** - Tracks analysis progress and results
2. **ProcessProductAnalysis Job** - Main orchestrator job for async analysis
3. **AnalysisController** - API endpoints for starting/monitoring analysis
4. **Enhanced Livewire Component** - Supports both sync and async modes
5. **Real-time Progress Polling** - JavaScript polls for progress updates

### Flow Diagram

```
User clicks "Analyze" → 
  ↓
Livewire validates input → 
  ↓
API creates AnalysisSession + dispatches Job →
  ↓
JavaScript polls /api/analysis/progress/{sessionId} →
  ↓
Job updates progress in database →
  ↓
Frontend shows real-time progress →
  ↓
Job completes → Frontend redirects or shows results
```

## 🚀 Quick Setup

### 1. Environment Configuration

Add to your `.env`:

```bash
# Enable async analysis
ANALYSIS_ASYNC_ENABLED=true
QUEUE_CONNECTION=database

# Optional: Fine-tune settings
ANALYSIS_POLLING_INTERVAL=2000
ANALYSIS_QUEUE_TIMEOUT=300
ANALYSIS_QUEUE_MAX_TRIES=3
```

### 2. Run Migrations

```bash
php artisan migrate
```

### 3. Start Queue Workers

```bash
# Option A: Manual (for development)
php artisan analysis:workers start

# Option B: Daemon mode (for production)
php artisan analysis:workers start --daemon --workers=3

# Check status
php artisan analysis:workers status
```

## 🔧 Commands

### Worker Management

```bash
# Start workers (shows commands to run manually)
php artisan analysis:workers start

# Start 3 daemon workers for production
php artisan analysis:workers start --daemon --workers=3 --memory=512

# Check worker status
php artisan analysis:workers status

# Stop all workers
php artisan analysis:workers stop

# Restart workers
php artisan analysis:workers restart
```

### Session Cleanup

```bash
# Clean up sessions older than 24 hours
php artisan analysis:cleanup

# Custom cleanup (72 hours)
php artisan analysis:cleanup --hours=72

# Dry run to see what would be deleted
php artisan analysis:cleanup --dry-run
```

## 🔄 Backward Compatibility

The system supports both modes:

- **Async Mode**: `ANALYSIS_ASYNC_ENABLED=true` (default)
- **Sync Mode**: `ANALYSIS_ASYNC_ENABLED=false` (fallback)

This allows gradual migration and easy rollback if needed.

## 📊 Monitoring

### Queue Status

```bash
# Check pending jobs
php artisan queue:monitor analysis

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all
```

### Analysis Sessions

Check the `analysis_sessions` table:

```sql
SELECT status, COUNT(*) as count 
FROM analysis_sessions 
WHERE created_at > NOW() - INTERVAL 1 DAY 
GROUP BY status;
```

## 🏭 Production Deployment

### 1. Supervisor Configuration

Create `/etc/supervisor/conf.d/analysis-workers.conf`:

```ini
[program:analysis-workers]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/artisan queue:work analysis --queue=analysis --sleep=3 --tries=3 --max-time=3600 --timeout=300
directory=/path/to/your/app
autostart=true
autorestart=true
user=www-data
numprocs=3
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/workers.log
stopwaitsecs=3600
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start analysis-workers:*
```

### 2. Cron Jobs

Add to crontab for cleanup:

```bash
# Clean up old analysis sessions daily
0 2 * * * cd /path/to/your/app && php artisan analysis:cleanup --hours=24 > /dev/null 2>&1
```

### 3. Monitoring Setup

Add health checks:

```bash
# Check if workers are running
php artisan analysis:workers status

# Monitor queue depth
php artisan queue:monitor analysis --max=10
```

## 🔍 Troubleshooting

### Workers Not Processing Jobs

1. **Check Queue Connection**:
   ```bash
   php artisan analysis:workers status
   ```

2. **Verify Database Setup**:
   ```bash
   php artisan queue:table
   php artisan migrate
   ```

3. **Check Worker Processes**:
   ```bash
   ps aux | grep "queue:work"
   ```

### Jobs Failing

1. **View Failed Jobs**:
   ```bash
   php artisan queue:failed
   ```

2. **Check Logs**:
   ```bash
   tail -f storage/logs/laravel.log
   ```

3. **Retry Failed Jobs**:
   ```bash
   php artisan queue:retry all
   ```

### Frontend Issues

1. **Check Browser Console** for JavaScript errors
2. **Verify API Endpoints** are accessible:
   ```bash
   curl -X POST http://yoursite.com/api/analysis/start
   ```

3. **Check CSRF Token** is present in meta tags

## 🧪 Testing

### Unit Tests

```bash
# Test analysis session model
php artisan test --filter=AnalysisSessionTest

# Test analysis controller
php artisan test --filter=AnalysisControllerTest

# Test async jobs
php artisan test --filter=ProcessProductAnalysisTest
```

### Manual Testing

1. **Start Workers**:
   ```bash
   php artisan analysis:workers start --daemon
   ```

2. **Submit Analysis** via the web interface

3. **Monitor Progress** in browser developer tools

4. **Check Database**:
   ```sql
   SELECT * FROM analysis_sessions ORDER BY created_at DESC LIMIT 5;
   ```

## ⚡ Performance Tuning

### Queue Workers

- **Development**: 1-2 workers with low memory
- **Production**: 3-5 workers with 512MB+ memory
- **High Load**: Scale workers based on queue depth

### Database

- Index on `analysis_sessions.user_session` and `status`
- Regular cleanup of old sessions
- Monitor `jobs` table size

### Polling Frequency

- **Default**: 2 seconds (good balance)
- **High Load**: 3-5 seconds (reduce server load)
- **Low Latency**: 1 second (more responsive)

## 🔐 Security Considerations

1. **Session Validation**: Each progress check verifies user session
2. **Rate Limiting**: Consider adding rate limits to API endpoints
3. **CSRF Protection**: All API calls require CSRF tokens
4. **Data Cleanup**: Automatic cleanup prevents data accumulation

## 📈 Scaling

### Horizontal Scaling

- Run workers on multiple servers
- Use Redis for shared job queue
- Load balance API endpoints

### Vertical Scaling

- Increase worker memory limits
- Optimize database queries
- Use faster storage for queue tables

## 🔮 Future Enhancements

1. **WebSocket Support** for real-time updates (no polling)
2. **Job Prioritization** for premium users
3. **Advanced Retry Logic** with exponential backoff
4. **Analytics Dashboard** for queue monitoring
5. **Auto-scaling Workers** based on queue depth

---

## 📞 Support

For issues or questions about the async analysis system:

1. Check this documentation
2. Review the troubleshooting section
3. Check application logs
4. Monitor queue worker status

Remember: The system maintains full backward compatibility, so you can always fall back to sync mode if needed! 