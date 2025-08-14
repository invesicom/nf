# Enterprise Alerting Strategy

## Overview
This document outlines the enterprise-grade context-aware notification system implemented for robust error handling and monitoring.

## Philosophy
Use tiered, context-aware alerting instead of blanket exception notifications.

## Core Principles
- **Business Impact First**: Alert based on user impact, not just technical failures
- **Progressive Severity**: Start with logging, escalate to notifications based on patterns
- **Avoid Alert Fatigue**: Too many notifications reduce response effectiveness  
- **Actionable Alerts Only**: Every alert should have a clear action or investigation path

## Severity Levels

### CRITICAL_P0
- **Description**: Service down, data corruption, security breaches
- **Examples**: All review analysis failing, Database corruption, Security vulnerabilities
- **Notification**: Immediate push notification
- **Response Time**: < 15 minutes

### HIGH_P1
- **Description**: Core functionality impacted, significant performance degradation
- **Examples**: BrightData service completely down, OpenAI API quota exceeded, All direct scraping blocked
- **Notification**: Push notification with throttling
- **Response Time**: < 2 hours

### MEDIUM_P2
- **Description**: Non-critical features degraded, fallback systems engaged
- **Examples**: Individual ASIN scraping failures, Proxy rotation needed, Rate limiting encountered
- **Notification**: Batch summary notifications
- **Response Time**: < 24 hours

### LOW_P3
- **Description**: Minor issues, expected failures, temporary glitches
- **Examples**: Single product 404, Temporary API timeouts, CAPTCHA encounters
- **Notification**: Logging only, daily summaries
- **Response Time**: Next business day

## Implementation Requirements

### Mandatory Usage
**ALL new services and error handling MUST use AlertManager**

```php
// ✅ CORRECT
app(AlertManager::class)->recordFailure(
    'New Service', 
    'API_ERROR', 
    'Connection failed', 
    ['endpoint' => $url], 
    $exception
);

// ❌ NEVER DO THIS
app(AlertService::class)->connectivityIssue(); // Direct calls forbidden
```

### New Service Integration Steps
1. **Determine service criticality** (PRIMARY/CORE/FALLBACK)
2. **Add to AlertManager::SERVICE_CRITICALITY** array
3. **Define error types and actions** in AlertManager::getRecommendedAction()
4. **Use recordFailure()** in all catch blocks and error conditions
5. **Create tests** that verify AlertManager integration

### Error Handling Pattern
```php
try {
    // Service logic
} catch (Exception $e) {
    app(AlertManager::class)->recordFailure(
        'ServiceName', 
        'ERROR_TYPE', 
        $e->getMessage(), 
        $context, 
        $e
    );
    throw $e;
}
```

### Context Requirements
Always include relevant context:
- IDs (ASIN, user ID, job ID)
- URLs and endpoints
- User data and request details
- Timing information

## Service-Specific Rules

### BrightData Service
- **Single failure**: Log only (P3) - expected due to Amazon's bot detection
- **Repeated failures**: Medium alert (P2) if >3 failures in 10 minutes
- **Complete service down**: High alert (P1) if all jobs failing for >30 minutes
- **API authentication**: High alert (P1) - blocks all future analysis

### OpenAI Service
- **Rate limiting**: Medium alert (P2) - affects analysis but expected behavior
- **Quota exceeded**: High alert (P1) - blocks all analysis until resolved
- **API errors**: Pattern-based alerting - single errors logged, repeated errors alerted

### Direct Scraping
- **Individual failures**: Log only (P3) - fallback service, failures expected
- **CAPTCHA detection**: Medium alert (P2) if affects multiple requests
- **Complete blocking**: Medium alert (P2) - fallback service, not critical

## Forbidden Patterns

### NEVER Do These:
- ❌ Call AlertService methods directly (connectivityIssue, openaiQuotaExceeded, etc.)
- ❌ Catch exceptions without calling AlertManager::recordFailure()
- ❌ Use generic error types - be specific (API_ERROR, TIMEOUT, QUOTA_EXCEEDED, etc.)

## Alert Content Requirements
- **Context**: Always include business impact and affected functionality
- **Actionability**: Provide clear next steps or investigation guidance
- **Severity Justification**: Explain why this alert level was chosen
- **Recovery Information**: Include any automatic recovery attempts made

## Monitoring Best Practices
- **Error Rate Thresholds**: Monitor error rates, not just individual errors
- **Business Metrics**: Track success rates for core user journeys
- **Alert Effectiveness**: Regularly review alert response times and outcomes
- **False Positive Reduction**: Continuously tune alert conditions to reduce noise

## Maintenance Guidelines

### Adding New Services
1. Determine service criticality (PRIMARY for core features, CORE for important, FALLBACK for optional)
2. Add to AlertManager::SERVICE_CRITICALITY mapping
3. Define specific error types and actions in getRecommendedAction()
4. Use recordFailure() for all error conditions
5. Test alert integration, not just business logic

### Modifying Existing Services
- **Requirement**: Ensure all error paths use AlertManager::recordFailure()
- **Verification**: Grep for 'catch' blocks and verify AlertManager usage
- **Testing**: Test error scenarios to ensure alerts are triggered correctly

## Technical Implementation
The AlertManager service (`app/Services/AlertManager.php`) centralizes all alerting logic with:
- Error rate tracking with sliding time windows
- Service criticality mapping (PRIMARY > CORE > FALLBACK)  
- Business impact assessment
- Intelligent throttling and recovery suppression
- Context enrichment with actionable recommendations
