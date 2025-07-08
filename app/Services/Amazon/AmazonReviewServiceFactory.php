<?php

namespace App\Services\Amazon;

use App\Services\LoggingService;

/**
 * Factory for creating Amazon review service instances.
 * 
 * This factory allows switching between different Amazon review fetching
 * implementations based on environment configuration.
 */
class AmazonReviewServiceFactory
{
    /**
     * Create an Amazon review service instance based on configuration.
     * 
     * @return AmazonReviewServiceInterface
     */
    public static function create(): AmazonReviewServiceInterface
    {
        $serviceType = env('AMAZON_REVIEW_SERVICE', 'unwrangle');
        
        switch (strtolower($serviceType)) {
            case 'scraping':
            case 'direct':
            case 'scrape':
                LoggingService::log('Using direct Amazon scraping service');
                return new AmazonScrapingService();
                
            case 'unwrangle':
            case 'api':
            default:
                LoggingService::log('Using Unwrangle API service');
                return new AmazonFetchService();
        }
    }

    /**
     * Get the currently configured service type.
     * 
     * @return string
     */
    public static function getCurrentServiceType(): string
    {
        return env('AMAZON_REVIEW_SERVICE', 'unwrangle');
    }

    /**
     * Check if direct scraping is enabled.
     * 
     * @return bool
     */
    public static function isScrapingEnabled(): bool
    {
        $serviceType = strtolower(env('AMAZON_REVIEW_SERVICE', 'unwrangle'));
        return in_array($serviceType, ['scraping', 'direct', 'scrape']);
    }

    /**
     * Check if Unwrangle API is enabled.
     * 
     * @return bool
     */
    public static function isUnwrangleEnabled(): bool
    {
        $serviceType = strtolower(env('AMAZON_REVIEW_SERVICE', 'unwrangle'));
        return in_array($serviceType, ['unwrangle', 'api']);
    }

    /**
     * Get available service types.
     * 
     * @return array
     */
    public static function getAvailableServices(): array
    {
        return [
            'unwrangle' => [
                'name' => 'Unwrangle API',
                'description' => 'Uses Unwrangle API service ($90/month)',
                'class' => AmazonFetchService::class,
                'env_values' => ['unwrangle', 'api'],
            ],
            'scraping' => [
                'name' => 'Direct Scraping',
                'description' => 'Direct Amazon scraping (free, requires cookies)',
                'class' => AmazonScrapingService::class,
                'env_values' => ['scraping', 'direct', 'scrape'],
            ],
        ];
    }
} 