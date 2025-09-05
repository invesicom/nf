<?php

namespace App\Services\Providers;

use App\Services\LLMProviderInterface;
use App\Services\LoggingService;
use App\Services\PromptGenerationService;
use Illuminate\Support\Facades\Http;

class DeepSeekProvider implements LLMProviderInterface
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
            return ['results' => []];
        }

        LoggingService::log('Sending '.count($reviews).' reviews to DeepSeek for analysis');

        // Use centralized prompt generation service
        $promptData = PromptGenerationService::generateReviewAnalysisPrompt(
            $reviews,
            'chat', // DeepSeek uses chat format
            PromptGenerationService::getProviderTextLimit('deepseek')
        );

        try {
            $endpoint = rtrim($this->baseUrl, '/').'/chat/completions';
            $maxTokens = $this->getOptimizedMaxTokens(count($reviews));

            LoggingService::log("Making DeepSeek API request to: {$endpoint}");

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'ReviewAnalyzer-DeepSeek/1.0',
            ])->timeout(120)->post($endpoint, [
                'model'    => $this->model,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => PromptGenerationService::getProviderSystemMessage('deepseek'),
                    ],
                    [
                        'role'    => 'user',
                        'content' => $promptData['user'],
                    ],
                ],
                'temperature' => 0.0,
                'max_tokens'  => $maxTokens,
            ]);

            if ($response->successful()) {
                LoggingService::log('DeepSeek API request successful');
                $result = $response->json();

                return $this->parseResponse($result, $reviews);
            } else {
                throw new \Exception('DeepSeek API error: '.$response->status().' - '.$response->body());
            }
        } catch (\Exception $e) {
            LoggingService::log('DeepSeek analysis failed: '.$e->getMessage());

            throw $e;
        }
    }

    public function getOptimizedMaxTokens(int $reviewCount): int
    {
        // DeepSeek-V3 is efficient, needs fewer tokens than GPT-4
        $baseTokens = $reviewCount * 8; // Less than GPT-4o-mini
        $buffer = min(800, $reviewCount * 4);

        return $baseTokens + $buffer;
    }

    public function isAvailable(): bool
    {
        try {
            if (empty($this->apiKey) && !$this->isLocalDeployment()) {
                return false;
            }

            // Quick health check
            $endpoint = rtrim($this->baseUrl, '/').'/models';
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->timeout(10)->get($endpoint);

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        $deployment = $this->isLocalDeployment() ? 'Self-Hosted' : 'API';

        return "DeepSeek-{$deployment}-{$this->model}";
    }

    public function getEstimatedCost(int $reviewCount): float
    {
        if ($this->isLocalDeployment()) {
            // Self-hosted costs are infrastructure-based, not per-token
            return 0.0;
        }

        // DeepSeek API pricing (significantly cheaper than OpenAI)
        $avgInputTokens = $reviewCount * 50;
        $avgOutputTokens = $reviewCount * 8;

        $inputCost = ($avgInputTokens / 1000000) * 0.27;  // $0.27 per 1M tokens
        $outputCost = ($avgOutputTokens / 1000000) * 1.10; // $1.10 per 1M tokens

        return $inputCost + $outputCost;
    }

    private function isLocalDeployment(): bool
    {
        return str_contains($this->baseUrl, 'localhost') ||
               str_contains($this->baseUrl, '127.0.0.1') ||
               str_contains($this->baseUrl, '192.168.') ||
               str_contains($this->baseUrl, '10.0.');
    }


    private function parseResponse($response, $reviews): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';

        // Debug: Log the actual response content
        LoggingService::log('DeepSeek raw response content: ' . substr($content, 0, 500) . (strlen($content) > 500 ? '...' : ''));
        LoggingService::log('DeepSeek response length: ' . strlen($content) . ' characters');

        // Parse JSON response with enhanced extraction (similar to Ollama)
        try {
            // Try direct JSON decode first
            $scores = json_decode($content, true);
            
            // If direct decode fails, try extracting JSON from markdown or wrapped content
            if (!is_array($scores)) {
                LoggingService::log('Direct JSON decode failed, attempting enhanced extraction');
                
                // Try extracting JSON from markdown code blocks
                if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/s', $content, $matches)) {
                    LoggingService::log('Found JSON in markdown code block');
                    $scores = json_decode($matches[1], true);
                } 
                // Try extracting JSON array from anywhere in the content
                elseif (preg_match('/(\[(?:[^[\]]+|(?1))*\])/', $content, $matches)) {
                    LoggingService::log('Found JSON array pattern in content');
                    $scores = json_decode($matches[1], true);
                }
                // Try extracting from lines that look like JSON
                elseif (preg_match('/^.*?(\[.*\]).*?$/s', $content, $matches)) {
                    LoggingService::log('Attempting to extract JSON from full content');
                    $scores = json_decode($matches[1], true);
                }
            }
            
            // Debug: Log JSON decode result
            LoggingService::log('JSON decode result type: ' . gettype($scores));
            if (is_array($scores)) {
                LoggingService::log('JSON decode successful, array length: ' . count($scores));
                LoggingService::log('First few elements: ' . json_encode(array_slice($scores, 0, 3)));
            } else {
                LoggingService::log('JSON decode failed or returned non-array: ' . json_encode($scores));
            }

            if (!is_array($scores)) {
                throw new \Exception('Invalid JSON response format - expected array, got: ' . gettype($scores));
            }

            return $this->formatAnalysisResults($scores);
        } catch (\Exception $e) {
            LoggingService::log('Failed to parse DeepSeek response: '.$e->getMessage());

            throw new \Exception('Failed to parse DeepSeek response');
        }
    }

    private function formatAnalysisResults(array $decoded): array
    {
        $results = [];
        foreach ($decoded as $item) {
            if (isset($item['id']) && isset($item['score'])) {
                $score = max(0, min(100, (int) $item['score'])); // Clamp to 0-100

                // Support new research-based format with additional metadata
                $label = $item['label'] ?? $this->generateLabel($score);
                $confidence = isset($item['confidence']) ? (float) $item['confidence'] : $this->calculateConfidenceFromScore($score);

                $results[$item['id']] = [
                    'score'       => $score,
                    'label'       => $label,
                    'confidence'  => $confidence,
                    'explanation' => $this->generateExplanationFromLabel($label, $score),
                ];
            }
        }

        return [
            'detailed_scores'   => $results,
            'analysis_provider' => $this->getProviderName(),
            'total_cost'        => $this->getEstimatedCost(count($decoded)),
        ];
    }

    private function generateLabel(int $score): string
    {
        if ($score >= 85) {
            return 'fake';
        } elseif ($score >= 40) {
            return 'uncertain';
        } else {
            return 'genuine';
        }
    }

    private function calculateConfidenceFromScore(int $score): float
    {
        // Higher confidence for extreme scores, lower for middle range
        if ($score >= 90 || $score <= 10) {
            return 0.95;
        } elseif ($score >= 80 || $score <= 20) {
            return 0.85;
        } elseif ($score >= 70 || $score <= 30) {
            return 0.75;
        } elseif ($score >= 60 || $score <= 40) {
            return 0.65;
        } else {
            return 0.55; // Lowest confidence for uncertain middle range
        }
    }

    private function generateExplanationFromLabel(string $label, int $score): string
    {
        switch ($label) {
            case 'fake':
                return $score >= 95 ? 'Extremely suspicious: Multiple red flags detected' : 'High fake risk: Multiple suspicious indicators detected';
            case 'uncertain':
                return $score >= 60 ? 'Moderately suspicious: Some concerning patterns found' : 'Mildly suspicious: Minor inconsistencies noted';
            case 'genuine':
            default:
                return $score <= 10 ? 'Highly authentic: Strong genuine indicators' : 'Appears genuine: Natural language and specific details';
        }
    }
}
