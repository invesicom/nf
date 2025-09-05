<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Services\PromptGenerationService;

class OpenAIServiceAggregate implements LLMProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?? '';
        $this->model = config('services.openai.model', 'gpt-4o-mini');
        $this->baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');

        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
        }
    }

    public function analyzeReviews(array $reviews): array
    {
        if (empty($reviews)) {
            return [
                'fake_percentage' => 0.0,
                'confidence' => 'high',
                'explanation' => 'No reviews to analyze',
                'fake_examples' => [],
                'key_patterns' => [],
                'analysis_provider' => 'OpenAI-' . $this->model,
                'total_cost' => 0.0
            ];
        }

        LoggingService::log('Sending '.count($reviews).' reviews to OpenAI for aggregate analysis');

        // Use centralized prompt generation service
        $promptData = PromptGenerationService::generateReviewAnalysisPrompt(
            $reviews,
            'chat', // OpenAI uses chat format
            PromptGenerationService::getProviderTextLimit('openai')
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $promptData['system']],
                    ['role' => 'user', 'content' => $promptData['user']],
                ],
                'max_tokens' => $this->getOptimizedMaxTokens(count($reviews)),
                'temperature' => 0.1,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return $this->parseAggregateResponse($result);
            } else {
                throw new \Exception('OpenAI API error: ' . $response->status() . ' - ' . $response->body());
            }
        } catch (\Exception $e) {
            LoggingService::log('OpenAI analysis failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function parseAggregateResponse($response): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';
        
        LoggingService::log('OpenAI raw response: ' . substr($content, 0, 500) . '...');

        try {
            // Try direct JSON decode first
            $result = json_decode($content, true);
            
            // If direct decode fails, try extracting JSON from various formats
            if (!is_array($result)) {
                // Try extracting from markdown code blocks
                if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                    $result = json_decode($matches[1], true);
                }
                // Try extracting JSON object from anywhere
                elseif (preg_match('/(\{(?:[^{}]+|(?1))*\})/', $content, $matches)) {
                    $result = json_decode($matches[1], true);
                }
            }

            if (!is_array($result)) {
                throw new \Exception('Invalid JSON response format - expected object, got: ' . gettype($result));
            }

            // Validate required fields
            if (!isset($result['fake_percentage']) || !isset($result['confidence']) || !isset($result['explanation'])) {
                throw new \Exception('Invalid response format - missing required fields (fake_percentage, confidence, explanation)');
            }

            LoggingService::log('OpenAI: Successfully parsed aggregate analysis - ' . $result['fake_percentage'] . '% fake, confidence: ' . $result['confidence']);
            
            return [
                'fake_percentage' => (float) $result['fake_percentage'],
                'confidence' => $result['confidence'],
                'explanation' => $result['explanation'],
                'fake_examples' => $result['fake_examples'] ?? [],
                'key_patterns' => $result['key_patterns'] ?? [],
                'analysis_provider' => 'OpenAI-' . $this->model,
                'total_cost' => $this->calculateCost($response)
            ];
        } catch (\Exception $e) {
            LoggingService::log('Failed to parse OpenAI response: ' . $e->getMessage());
            throw new \Exception('Failed to parse OpenAI response');
        }
    }

    public function getOptimizedMaxTokens(int $reviewCount): int
    {
        // For aggregate responses, we need much fewer tokens than individual scoring
        // Aggregate response: ~300-800 tokens vs individual: ~30 tokens per review
        $baseTokens = 600; // Base for aggregate response structure
        $buffer = min(1200, $reviewCount * 3); // Buffer for examples and patterns
        
        // Minimum 1000 tokens for meaningful aggregate analysis
        $minTokens = 1000;

        return max($minTokens, $baseTokens + $buffer);
    }

    private function calculateCost($response): float
    {
        $usage = $response['usage'] ?? [];
        $inputTokens = $usage['prompt_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? 0;

        // GPT-4o-mini pricing (as of 2024)
        $inputCostPer1k = 0.00015;  // $0.15 per 1K input tokens
        $outputCostPer1k = 0.0006;  // $0.60 per 1K output tokens

        $inputCost = ($inputTokens / 1000) * $inputCostPer1k;
        $outputCost = ($outputTokens / 1000) * $outputCostPer1k;

        return $inputCost + $outputCost;
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getProviderName(): string
    {
        return 'OpenAI-' . $this->model;
    }

    public function getEstimatedCost(int $reviewCount): float
    {
        // Rough estimate for aggregate analysis
        // Much cheaper than individual scoring due to fewer tokens
        $inputTokens = $reviewCount * 20; // Compact format
        $outputTokens = 200; // Aggregate response
        
        $inputCostPer1k = 0.00015;
        $outputCostPer1k = 0.0006;
        
        return (($inputTokens / 1000) * $inputCostPer1k) + (($outputTokens / 1000) * $outputCostPer1k);
    }
}
