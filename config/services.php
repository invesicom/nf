<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'rapidapi' => [
        'key'  => env('RAPIDAPI_KEY'),
        'host' => env('RAPIDAPI_HOST', 'amazon23.p.rapidapi.com'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => env('OPENAI_TIMEOUT', 120),
        'parallel_threshold' => env('OPENAI_PARALLEL_THRESHOLD', 50),
        'chunk_size' => env('OPENAI_CHUNK_SIZE', 25),
    ],

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY', ''),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-v3'),
        'timeout' => env('DEEPSEEK_TIMEOUT', 120),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'phi4:14b'),
        'timeout' => env('OLLAMA_TIMEOUT', 120),
    ],

    'llm' => [
        'primary_provider' => env('LLM_PRIMARY_PROVIDER', 'openai'),
        'fallback_order' => ['ollama', 'deepseek', 'openai'],
        'auto_fallback' => env('LLM_AUTO_FALLBACK', true),
        'cost_tracking' => env('LLM_COST_TRACKING', true),
    ],

    'pushover' => [
        'token' => env('PUSHOVER_APP_TOKEN'),
        'user' => env('PUSHOVER_USER_KEY'),
        'api_url' => env('PUSHOVER_API_URL', 'https://api.pushover.net/1/messages.json'),
    ],

    'mailtrain' => [
        'base_url' => env('MAILTRAIN_BASE_URL'),
        'api_token' => env('MAILTRAIN_API_TOKEN'),
        'list_id' => env('MAILTRAIN_LIST_ID'),
        'timeout' => env('MAILTRAIN_TIMEOUT', 30),
    ],

    'amazon_scraping' => [
        'max_pages' => env('AMAZON_SCRAPING_MAX_PAGES', 10),
        'target_reviews' => env('AMAZON_SCRAPING_TARGET_REVIEWS', 30),
        'max_reviews' => env('AMAZON_SCRAPING_MAX_REVIEWS', 100),
    ],

    // Amazon validation is now handled client-side only
    // Server skips validation to avoid IP throttling

];
