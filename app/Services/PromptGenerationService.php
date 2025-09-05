<?php

namespace App\Services;

class PromptGenerationService
{
    /**
     * Generate review analysis prompt for any LLM provider.
     *
     * @param array $reviews Array of review data
     * @param string $format 'single' for single prompt, 'chat' for system/user messages
     * @param int $maxTextLength Maximum characters per review text
     * @return array Formatted prompt data
     */
    public static function generateReviewAnalysisPrompt(
        array $reviews,
        string $format = 'single',
        int $maxTextLength = 300
    ): array {
        $coreInstructions = self::getCoreAnalysisInstructions();
        $reviewsText = self::formatReviewsForAnalysis($reviews, $maxTextLength);
        $responseFormat = self::getResponseFormatInstructions();

        if ($format === 'chat') {
            return [
                'system' => $coreInstructions,
                'user' => $reviewsText . "\n\n" . $responseFormat,
            ];
        }

        return [
            'prompt' => $coreInstructions . "\n\n" . $reviewsText . "\n\n" . $responseFormat,
        ];
    }

    /**
     * Get the core analysis instructions used by all providers.
     */
    private static function getCoreAnalysisInstructions(): string
    {
        return "Analyze reviews for fake probability (0-100 scale: 0=genuine, 100=fake).\n\n" .
               "Be SUSPICIOUS and thorough - most products have 15-40% fake reviews. " .
               "Consider: Generic language (+20), specific complaints (-20), " .
               "unverified purchase (+10), verified purchase (-5), " .
               "excessive positivity (+15), balanced tone (-10).\n\n" .
               "Scoring: Use full range 0-100. ≤39=genuine, 40-84=uncertain/suspicious, " .
               "≥85=fake. Be aggressive with scoring - obvious fakes should score 85-100.";
    }

    /**
     * Format reviews for analysis with efficient structure.
     * Uses compact format to reduce token usage by ~12-15% without content loss.
     */
    private static function formatReviewsForAnalysis(array $reviews, int $maxTextLength): string
    {
        $reviewsText = '';

        foreach ($reviews as $review) {
            $verified = isset($review['meta_data']['verified_purchase']) && $review['meta_data']['verified_purchase'] 
                ? 'V' 
                : 'U';
            $rating = $review['rating'] ?? '?';

            $text = '';
            if (isset($review['review_text'])) {
                $text = self::cleanUtf8Text(substr($review['review_text'], 0, $maxTextLength));
            } elseif (isset($review['text'])) {
                $text = self::cleanUtf8Text(substr($review['text'], 0, $maxTextLength));
            }

            // Compact format: ID|V/U|Rating★|Text
            // Saves ~30 chars per review vs "Review ID (Verified, Rating★): Text"
            $reviewsText .= "{$review['id']}|{$verified}|{$rating}★|{$text}\n";
        }

        return $reviewsText;
    }

    /**
     * Get response format instructions.
     */
    private static function getResponseFormatInstructions(): string
    {
        return 'Respond with JSON array: [{"id":"review_id","score":number,"label":"genuine|uncertain|fake"}]';
    }

    /**
     * Clean text to ensure valid UTF-8 encoding for JSON serialization.
     */
    private static function cleanUtf8Text(string $text): string
    {
        // Remove or replace invalid UTF-8 sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remove null bytes and other problematic characters
        $text = str_replace(["\0", "\x1A"], '', $text);

        // Ensure string is valid UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }

        return trim($text);
    }

    /**
     * Generate provider-specific system message for chat-based models.
     * 
     * @param string $providerName Provider identifier (openai, deepseek, etc.)
     * @return string Optimized system message for the provider
     */
    public static function getProviderSystemMessage(string $providerName): string
    {
        $baseMessage = 'You are an expert Amazon review authenticity detector. ' .
                      'Be SUSPICIOUS and thorough - most products have 15-40% fake reviews. ' .
                      'Score 0-100 where 0=definitely genuine, 100=definitely fake. ' .
                      'Use the full range: 20-40 for suspicious, 50-70 for likely fake, 80+ for obvious fakes. ' .
                      'Return ONLY JSON: [{"id":"X","score":Y}]';

        // Provider-specific optimizations can be added here
        switch (strtolower($providerName)) {
            case 'openai':
                return $baseMessage; // OpenAI works well with concise instructions
            case 'deepseek':
                return $baseMessage; // DeepSeek similar to OpenAI
            case 'ollama':
            default:
                return $baseMessage; // Default format
        }
    }

    /**
     * Get provider-specific text length limits.
     */
    public static function getProviderTextLimit(string $providerName): int
    {
        switch (strtolower($providerName)) {
            case 'openai':
                return 400; // OpenAI can handle longer text efficiently
            case 'deepseek':
                return 300; // DeepSeek optimized for shorter text
            case 'ollama':
                return 300; // Ollama local models prefer shorter text
            default:
                return 300; // Conservative default
        }
    }

    /**
     * Validate that reviews array has the expected structure.
     */
    public static function validateReviewsStructure(array $reviews): bool
    {
        if (empty($reviews)) {
            return false;
        }

        foreach ($reviews as $review) {
            if (!isset($review['id'])) {
                return false;
            }
            
            if (!isset($review['review_text']) && !isset($review['text'])) {
                return false;
            }
        }

        return true;
    }
}
