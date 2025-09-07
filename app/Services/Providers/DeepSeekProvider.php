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

        // DeepSeek-V3 has 64k token context window - can handle 99+ reviews without chunking
        // With format optimization, only chunk for extremely large datasets (200+ reviews)
        $reviewCount = count($reviews);
        $chunkingThreshold = 200; // Increased from 50 - DeepSeek can handle much more
        
        if ($reviewCount > $chunkingThreshold) {
            LoggingService::log("Extremely large review set detected ({$reviewCount} reviews > {$chunkingThreshold}), using chunking for DeepSeek");
            return $this->analyzeReviewsInChunks($reviews);
        }

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
        // DeepSeek-V3 needs significantly more tokens for JSON responses
        // Each review needs ~25-30 tokens for: {"id":"R123...","score":85,"label":"fake","confidence":0.95,"explanation":"..."},
        $baseTokens = $reviewCount * 30; // Increased from 15 to 30
        $buffer = min(4000, $reviewCount * 15); // Much larger buffer for JSON overhead + explanations
        
        // Minimum 3000 tokens even for small sets to prevent truncation
        $minTokens = 3000;

        return max($minTokens, $baseTokens + $buffer);
    }

    /**
     * Process large review sets in chunks to avoid token limits and timeouts.
     */
    private function analyzeReviewsInChunks(array $reviews): array
    {
        $chunkSize = 25; // Smaller chunks for DeepSeek
        $chunks = array_chunk($reviews, $chunkSize);
        $allResults = [];
        $failedChunks = 0;
        
        $totalChunks = count($chunks);
        LoggingService::log("Processing {$totalChunks} chunks of {$chunkSize} reviews each for DeepSeek");
        
        foreach ($chunks as $index => $chunk) {
            $chunkNumber = $index + 1;
            LoggingService::log("Processing DeepSeek chunk {$chunkNumber}/{$totalChunks} with " . count($chunk) . " reviews");
            
            try {
                $promptData = PromptGenerationService::generateReviewAnalysisPrompt(
                    $chunk,
                    'chat',
                    PromptGenerationService::getProviderTextLimit('deepseek')
                );
                
                $endpoint = rtrim($this->baseUrl, '/').'/chat/completions';
                $maxTokens = $this->getOptimizedMaxTokens(count($chunk));
                
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
                    $result = $response->json();
                    $chunkResults = $this->parseResponse($result, $chunk);
                    
                    if (isset($chunkResults['detailed_scores'])) {
                        $allResults = array_merge($allResults, $chunkResults['detailed_scores']);
                    }
                } else {
                    LoggingService::log("DeepSeek chunk {$chunkNumber} failed: " . $response->body());
                    $failedChunks++;
                }
                
                // Small delay between chunks to avoid rate limiting
                usleep(500000); // 0.5 seconds
                
            } catch (\Exception $e) {
                LoggingService::log("DeepSeek chunk {$chunkNumber} error: " . $e->getMessage());
                $failedChunks++;
            }
        }
        
        LoggingService::log("DeepSeek chunking completed: {$totalChunks} chunks, {$failedChunks} failed, " . count($allResults) . " results");
        
        // Allow up to 50% chunk failures for partial results
        if ($failedChunks > ($totalChunks / 2)) {
            throw new \Exception("Too many DeepSeek chunks failed ({$failedChunks}/{$totalChunks})");
        }
        
        return [
            'detailed_scores'   => $allResults,
            'analysis_provider' => $this->getProviderName(),
            'total_cost'        => $this->getEstimatedCost(count($reviews)),
        ];
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

        // Debug: Log the actual response content for troubleshooting
        LoggingService::log('DeepSeek raw response content: ' . substr($content, 0, 1000) . (strlen($content) > 1000 ? '...' : ''));
        LoggingService::log('DeepSeek response length: ' . strlen($content) . ' characters');

        // Parse aggregate JSON response
        try {
            // Try direct JSON decode first
            $result = json_decode($content, true);
            
            // If direct decode fails, try extracting JSON from markdown or wrapped content
            if (!is_array($result)) {
                // Try extracting JSON from markdown code blocks (most common case)
                if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                    $result = json_decode($matches[1], true);
                } 
                // Handle truncated responses - try to extract partial JSON and fix it
                elseif (preg_match('/```(?:json)?\s*(\{.*)/s', $content, $matches)) {
                    $partialJson = $matches[1];
                    // If it ends incomplete, try to close it properly
                    if (!str_ends_with(trim($partialJson), '}')) {
                        $partialJson = rtrim($partialJson, ',') . '}';
                    }
                    $result = json_decode($partialJson, true);
                    if (is_array($result)) {
                        LoggingService::log('DeepSeek: Recovered aggregate data from truncated response');
                    }
                }
                // Try extracting JSON object from anywhere in the content
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

            LoggingService::log('DeepSeek: Successfully parsed aggregate analysis - ' . $result['fake_percentage'] . '% fake, confidence: ' . $result['confidence']);
            
            return [
                'fake_percentage' => (float) $result['fake_percentage'],
                'confidence' => $result['confidence'],
                'explanation' => $result['explanation'],
                'fake_examples' => $result['fake_examples'] ?? [],
                'key_patterns' => $result['key_patterns'] ?? [],
                'analysis_provider' => 'DeepSeek-API-' . $this->model,
                'total_cost' => 0.0001 // Placeholder cost
            ];
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
