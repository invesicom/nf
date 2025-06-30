<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Alert System Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration for the centralized alerting system.
    | Configure notification channels, recipients, and alert settings here.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Alert Recipients
    |--------------------------------------------------------------------------
    |
    | Define who should receive alert notifications. This can be a single
    | recipient or multiple recipients for different channels.
    |
    */
    'recipients' => [
        'pushover' => [
            'user' => env('PUSHOVER_USER_KEY'),
            'token' => env('PUSHOVER_APP_TOKEN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enabled Alert Types
    |--------------------------------------------------------------------------
    |
    | Specify which alert types should be sent. You can disable specific
    | alert types by setting them to false.
    |
    */
    'enabled_types' => [
        'amazon_session_expired' => env('ALERT_AMAZON_SESSION', true),
        'openai_quota_exceeded' => env('ALERT_OPENAI_QUOTA', true),
        'openai_api_error' => env('ALERT_OPENAI_API', true),
        'amazon_api_error' => env('ALERT_AMAZON_API', true),
        'api_timeout' => env('ALERT_API_TIMEOUT', true),
        'connectivity_issue' => env('ALERT_CONNECTIVITY_ISSUE', true),
        'system_error' => env('ALERT_SYSTEM_ERROR', true),
        'rate_limit_exceeded' => env('ALERT_RATE_LIMIT', true),
        'database_error' => env('ALERT_DATABASE_ERROR', true),
        'external_api_error' => env('ALERT_EXTERNAL_API', true),
        'security_alert' => env('ALERT_SECURITY', true),
        'performance_alert' => env('ALERT_PERFORMANCE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Alert Settings
    |--------------------------------------------------------------------------
    |
    | Global settings that apply to all alerts.
    |
    */
    'enabled' => env('ALERTS_ENABLED', true),
    'environment_prefix' => env('ALERT_ENV_PREFIX', true), // Prefix alerts with environment name
    'max_message_length' => 1024, // Pushover limit
    'max_title_length' => 250, // Pushover limit

    /*
    |--------------------------------------------------------------------------
    | Throttling Settings
    |--------------------------------------------------------------------------
    |
    | Configure alert throttling to prevent spam.
    |
    */
    'throttling' => [
        'enabled' => env('ALERT_THROTTLING_ENABLED', true),
        'cache_store' => env('ALERT_THROTTLING_CACHE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Settings
    |--------------------------------------------------------------------------
    |
    | Settings for development and testing environments.
    |
    */
    'development' => [
        'log_only' => env('ALERT_LOG_ONLY', false), // Only log alerts, don't send notifications
        'test_recipient' => env('ALERT_TEST_RECIPIENT'), // Override recipient for testing
    ],
]; 