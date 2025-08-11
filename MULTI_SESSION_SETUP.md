# Amazon Multi-Session Cookie Management

This document explains how to configure and use the new multi-session Amazon cookie management system implemented to address GitHub issue #4.

## Overview

The system now supports up to 10 Amazon cookie sessions that automatically rotate in a round-robin fashion. This helps:
- Distribute scraping load across multiple sessions
- Reduce CAPTCHA challenges from Amazon
- Provide redundancy when sessions become unhealthy
- Better manage rate limiting

## Configuration

### Environment Variables

Configure cookie sessions using numbered environment variables:

```bash
# Primary session
AMAZON_COOKIES_1="session-id=123-456-789; session-id-time=2082787201l; session-token=abc123xyz"

# Secondary session  
AMAZON_COOKIES_2="session-id=987-654-321; session-id-time=2082787202l; session-token=def456uvw"

# Additional sessions (up to AMAZON_COOKIES_10)
AMAZON_COOKIES_3="session-id=555-666-777; session-id-time=2082787203l; session-token=ghi789rst"
```

### Backward Compatibility

The legacy `AMAZON_COOKIES` environment variable is still supported as a fallback:

```bash
# Legacy fallback (used when no numbered sessions are configured)
AMAZON_COOKIES="session-id=legacy; session-token=legacy"
```

## How It Works

### Automatic Rotation

The system automatically rotates through available sessions using round-robin:
1. Session 1 → Session 2 → Session 3 → Session 1 (repeat)
2. Each scraping request uses the next session in rotation
3. Load is distributed evenly across all healthy sessions

### Health Management

Sessions are automatically marked as "unhealthy" when:
- CAPTCHA challenges are detected
- Authentication errors occur
- Other blocking indicators are found

Unhealthy sessions:
- Are skipped during rotation for 30 minutes (configurable)
- Are marked with the reason and timestamp
- Automatically become healthy again after cooldown period

### Session Alerts

When issues are detected, alerts now include specific session information:
- Which session experienced the problem (`AMAZON_COOKIES_X`)
- Session name and environment variable
- Specific indicators that triggered the alert

Example alert: "Amazon CAPTCHA detected - cookies need renewal. Session: Session 2 (AMAZON_COOKIES_2)"

## Management Commands

### List Sessions

View all configured sessions and their health status:

```bash
php artisan amazon:cookie-sessions list
```

Output:
```
Amazon Cookie Sessions (3 configured):
+-------+----------+--------------------+---------+---------+---------+
| Index | Name     | Environment Var    | Cookies | Status  | Current |
+-------+----------+--------------------+---------+---------+---------+
| 1     | Session 1| AMAZON_COOKIES_1   | 5       | Healthy | No      |
| 2     | Session 2| AMAZON_COOKIES_2   | 5       | Unhealthy| No     |
| 3     | Session 3| AMAZON_COOKIES_3   | 5       | Healthy | Yes     |
+-------+----------+--------------------+---------+---------+---------+
```

### Detailed Information

Get detailed information about all sessions:

```bash
php artisan amazon:cookie-sessions info
```

### Reset Session Health

Reset health status for all sessions:

```bash
php artisan amazon:cookie-sessions reset-health
```

Reset health for a specific session:

```bash
php artisan amazon:cookie-sessions reset-health --session=2
```

## Debugging

### Debug Command Enhancement

The debug command now shows session information:

```bash
php artisan amazon:debug-scraping B001TEST
```

Output includes:
- Number of available sessions
- Health status of each session
- Which session is being used for the test

### Log Messages

Enhanced logging shows session usage:
- "Setup cookies from multi-session manager" - Session selection
- "Marked Amazon session as unhealthy" - When sessions become unhealthy
- "CAPTCHA/blocking detected" - Includes session context

## Best Practices

### Session Management

1. **Start with 3-5 sessions** - More sessions = better load distribution
2. **Use different proxy IPs** - If using proxies, pair different sessions with different IPs
3. **Monitor session health** - Check `amazon:cookie-sessions list` regularly
4. **Rotate cookies manually** - When sessions consistently become unhealthy

### Cookie Collection

1. **Use different browsers/devices** - Collect cookies from different environments
2. **Different geographic locations** - If possible, use cookies from different regions
3. **Fresh sessions** - Periodically refresh cookie data from active Amazon sessions
4. **Test cookies** - Verify cookies work before adding to production

### Monitoring

1. **Set up alerts** - Monitor for CAPTCHA detection alerts
2. **Check rotation** - Ensure sessions are rotating properly
3. **Monitor success rates** - Track scraping success across sessions
4. **Regular maintenance** - Replace unhealthy sessions with fresh cookies

## Technical Details

### CookieSessionManager

Core service managing session rotation and health:
- `getNextCookieSession()` - Gets next session in rotation
- `markSessionUnhealthy()` - Marks session as unhealthy with cooldown
- `createCookieJar()` - Creates GuzzleHttp CookieJar from session
- `getSessionInfo()` - Returns detailed session status

### Integration Points

Services using multi-session system:
- `AmazonScrapingService` - Review scraping
- `AmazonProductDataService` - Product data collection
- `DebugAmazonScraping` - Debug command
- Future Amazon-related services

### Cache Usage

Session data is cached for performance:
- Rotation index: 24 hours
- Session health: Configurable cooldown (default 30 minutes)
- Cache keys: `amazon_cookie_rotation_index`, `amazon_session_health_{index}`

## Troubleshooting

### No Sessions Available

Error: "No Amazon cookie sessions available"

Solutions:
1. Configure at least one `AMAZON_COOKIES_X` variable
2. Check environment variable names (must be exact)
3. Verify cookie strings are not empty
4. Use legacy `AMAZON_COOKIES` as fallback

### All Sessions Unhealthy

Symptoms: All sessions marked as unhealthy

Solutions:
1. Run `amazon:cookie-sessions reset-health`
2. Update cookie data from fresh Amazon sessions
3. Check if IP addresses are blocked by Amazon
4. Verify proxy configuration if using proxies

### Sessions Not Rotating

Symptoms: Same session used repeatedly

Solutions:
1. Clear Laravel cache: `php artisan cache:clear`
2. Check for errors in logs
3. Verify multiple sessions are configured
4. Check session health status

### CAPTCHA Still Occurring

If CAPTCHAs persist despite multiple sessions:
1. Add more sessions (up to 10)
2. Increase delays between requests
3. Use residential proxies
4. Rotate proxy IPs along with sessions
5. Check cookie freshness

## Migration from Single Session

To migrate from the legacy single-session system:

1. **Keep existing setup working**: Leave `AMAZON_COOKIES` in place
2. **Add numbered sessions**: Configure `AMAZON_COOKIES_1`, `AMAZON_COOKIES_2`, etc.
3. **Test new system**: Use debug commands to verify rotation
4. **Monitor performance**: Check for reduced CAPTCHA incidents
5. **Remove legacy**: Once confident, remove `AMAZON_COOKIES`

The system will automatically prefer numbered sessions over legacy configuration.
