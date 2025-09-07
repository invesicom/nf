<?php

namespace App\Services\Providers;

use App\Services\LLMProviderInterface;
use App\Services\LoggingService;
use App\Services\PromptGenerationService;
use Illuminate\Support\Facades\Http;

class OllamaProviderAggregate implements LLMProviderInterface
{
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.ollama.base_url', 'http://localhost:11434');
        $this->model = config('services.ollama.model') ?: 'llama3.2:3b';
        $this->timeout = config('services.ollama.timeout', 120);
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
                'analysis_provider' => 'Ollama-' . $this->model,
                'total_cost' => 0.0
            ];
        }

        LoggingService::log('Sending '.count($reviews).' reviews to Ollama for aggregate analysis');

        // Use centralized prompt generation service
        $promptData = PromptGenerationService::generateReviewAnalysisPrompt(
            $reviews,
            'single', // Ollama uses single prompt format
            PromptGenerationService::getProviderTextLimit('ollama')
        );

        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/api/generate", [
                'model'   => $this->model,
                'prompt'  => $promptData['prompt'],
                'stream'  => false,
                'options' => [
                    'num_ctx'     => 8192,  // Increased context window for aggregate analysis
                    'num_predict' => 1024,  // Sufficient for aggregate response
                    'temperature' => 0.1,
                ],
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return $this->parseAggregateResponse($result);
            }

            $statusCode = $response->status();
            $body = $response->body();
            
            // Enhanced error detection for HTML responses (service down)
            if (str_starts_with(trim($body), '<html') || str_starts_with(trim($body), '<!DOCTYPE')) {
                throw new \Exception("Ollama service is returning HTML instead of JSON (HTTP {$statusCode}). This usually means Ollama is down or misconfigured. Check if Ollama is running on {$this->baseUrl}");
            }
            
            throw new \Exception("Ollama API request failed (HTTP {$statusCode}): " . substr($body, 0, 200));
        } catch (\Exception $e) {
            LoggingService::log('Ollama analysis failed: '.$e->getMessage());
            throw $e;
        }
    }

    private function parseAggregateResponse($response): array
    {
        $content = $response['response'] ?? '';
        
        LoggingService::log('Ollama raw response: ' . substr($content, 0, 500) . '...');

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
                // Try multiline JSON extraction
                elseif (preg_match('/\{[\s\S]*?\}/s', $content, $matches)) {
                    $result = json_decode($matches[0], true);
                }
            }

            if (!is_array($result)) {
                throw new \Exception('Invalid JSON response format - expected object, got: ' . gettype($result));
            }

            // Validate required fields
            if (!isset($result['fake_percentage']) || !isset($result['confidence']) || !isset($result['explanation'])) {
                throw new \Exception('Invalid response format - missing required fields (fake_percentage, confidence, explanation)');
            }

            LoggingService::log('Ollama: Successfully parsed aggregate analysis - ' . $result['fake_percentage'] . '% fake, confidence: ' . $result['confidence']);
            
            return [
                'fake_percentage' => (float) $result['fake_percentage'],
                'confidence' => $result['confidence'],
                'explanation' => $result['explanation'],
                'fake_examples' => $result['fake_examples'] ?? [],
                'key_patterns' => $result['key_patterns'] ?? [],
                'analysis_provider' => 'Ollama-' . $this->model,
                'total_cost' => 0.0 // Ollama is free
            ];
        } catch (\Exception $e) {
            LoggingService::log('Failed to parse Ollama response: '.$e->getMessage());
            throw new \Exception('Failed to parse Ollama response');
        }
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'Ollama-' . $this->model;
    }

    public function getOptimizedMaxTokens(int $reviewCount): int
    {
        // For aggregate responses, we need much fewer tokens than individual scoring
        return 1024; // Fixed size for aggregate analysis
    }

    public function getEstimatedCost(int $reviewCount): float
    {
        return 0.0; // Ollama is free
    }
}
