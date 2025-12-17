<?php

namespace App\Services\Providers;

use App\Services\LLMProviderInterface;
use App\Services\LoggingService;
use App\Services\PromptGenerationService;
use Illuminate\Support\Facades\Http;

class DeepSeekProviderAggregate implements LLMProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.deepseek.api_key', '');
        $this->baseUrl = config('services.deepseek.base_url', 'https://api.deepseek.com/v1');
        $this->model = config('services.deepseek.model', 'deepseek-v3');
    }

    public function analyzeReviews(array $reviews): array
    {
        if (empty($reviews)) {
            return [
                'fake_percentage'   => 0.0,
                'confidence'        => 'high',
                'explanation'       => 'No reviews to analyze',
                'fake_examples'     => [],
                'key_patterns'      => [],
                'analysis_provider' => 'DeepSeek-API-'.$this->model,
                'total_cost'        => 0.0,
            ];
        }

        LoggingService::log('Sending '.count($reviews).' reviews to DeepSeek for aggregate analysis');

        // Use centralized prompt generation service
        $promptData = PromptGenerationService::generateReviewAnalysisPrompt(
            $reviews,
            'chat', // DeepSeek uses chat format
            PromptGenerationService::getProviderTextLimit('deepseek')
        );

        try {
            $endpoint = rtrim($this->baseUrl, '/').'/chat/completions';
            $maxTokens = $this->getOptimizedMaxTokens(count($reviews));

            LoggingService::log('Making DeepSeek API request to: '.$endpoint);

            $response = Http::timeout(config('services.deepseek.timeout', 60))
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($endpoint, [
                    'model'      => $this->model,
                    'messages'   => [
                        ['role' => 'system', 'content' => $promptData['system']],
                        ['role' => 'user', 'content' => $promptData['user']],
                    ],
                    'max_tokens'  => $maxTokens,
                    'temperature' => 0.1,
                ]);

            if ($response->successful()) {
                LoggingService::log('DeepSeek API request successful');
                $result = $response->json();

                return $this->parseAggregateResponse($result);
            } else {
                throw new \Exception('DeepSeek API error: '.$response->status().' - '.$response->body());
            }
        } catch (\Exception $e) {
            LoggingService::log('DeepSeek analysis failed: '.$e->getMessage());

            throw $e;
        }
    }

    private function parseAggregateResponse($response): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';

        LoggingService::log('DeepSeek raw response content: '.substr($content, 0, 1000).(strlen($content) > 1000 ? '...' : ''));
        LoggingService::log('DeepSeek response length: '.strlen($content).' characters');

        try {
            // Try direct JSON decode first
            $result = json_decode($content, true);

            // If direct decode fails, try extracting JSON from markdown or wrapped content
            if (!is_array($result)) {
                // Try extracting JSON from markdown code blocks
                if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                    $result = json_decode($matches[1], true);
                }
                // Handle truncated responses
                elseif (preg_match('/```(?:json)?\s*(\{.*)/s', $content, $matches)) {
                    $partialJson = $matches[1];
                    if (!str_ends_with(trim($partialJson), '}')) {
                        $partialJson = rtrim($partialJson, ',').'}';
                    }
                    $result = json_decode($partialJson, true);
                }
                // Try extracting JSON object from anywhere in the content
                elseif (preg_match('/(\{(?:[^{}]+|(?1))*\})/', $content, $matches)) {
                    $result = json_decode($matches[1], true);
                }
            }

            if (!is_array($result)) {
                throw new \Exception('Invalid JSON response format - expected object, got: '.gettype($result));
            }

            // Validate required fields
            if (!isset($result['fake_percentage']) || !isset($result['confidence']) || !isset($result['explanation'])) {
                throw new \Exception('Invalid response format - missing required fields (fake_percentage, confidence, explanation)');
            }

            LoggingService::log('DeepSeek: Successfully parsed aggregate analysis - '.$result['fake_percentage'].'% fake, confidence: '.$result['confidence']);

            return [
                'fake_percentage'   => (float) $result['fake_percentage'],
                'confidence'        => $result['confidence'],
                'explanation'       => $result['explanation'],
                'fake_examples'     => $result['fake_examples'] ?? [],
                'key_patterns'      => $result['key_patterns'] ?? [],
                'analysis_provider' => 'DeepSeek-API-'.$this->model,
                'total_cost'        => 0.0001, // Placeholder cost
            ];
        } catch (\Exception $e) {
            LoggingService::log('Failed to parse DeepSeek response: '.$e->getMessage());

            throw new \Exception('Failed to parse DeepSeek response');
        }
    }

    public function getOptimizedMaxTokens(int $reviewCount): int
    {
        // For aggregate responses, we need much fewer tokens than individual scoring
        // Aggregate response: ~200-500 tokens vs individual: ~30 tokens per review
        $baseTokens = 500; // Base for aggregate response structure
        $buffer = min(1000, $reviewCount * 2); // Small buffer for examples and patterns

        // Minimum 800 tokens for meaningful aggregate analysis
        $minTokens = 800;

        return max($minTokens, $baseTokens + $buffer);
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) &&
               !$this->isLocalhost() &&
               !empty($this->baseUrl);
    }

    public function getProviderName(): string
    {
        return 'DeepSeek-API-'.$this->model;
    }

    public function getEstimatedCost(int $reviewCount): float
    {
        // DeepSeek pricing estimate for aggregate analysis
        return 0.0001; // Placeholder cost
    }

    private function isLocalhost(): bool
    {
        return str_contains($this->baseUrl, 'localhost') ||
               str_contains($this->baseUrl, '127.0.0.1') ||
               str_contains($this->baseUrl, '192.168.') ||
               str_contains($this->baseUrl, '10.0.');
    }
}
