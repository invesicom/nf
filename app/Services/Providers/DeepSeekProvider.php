<?php

namespace App\Services\Providers;

use App\Services\LLMProviderInterface;
use App\Services\LoggingService;
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

        $prompt = $this->buildOptimizedPrompt($reviews);
        
        try {
            $endpoint = rtrim($this->baseUrl, '/') . '/chat/completions';
            $maxTokens = $this->getOptimizedMaxTokens(count($reviews));
            
            LoggingService::log("Making DeepSeek API request to: {$endpoint}");
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'ReviewAnalyzer-DeepSeek/1.0',
            ])->timeout(120)->post($endpoint, [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert Amazon review authenticity detector. Be SUSPICIOUS and thorough - most products have 15-40% fake reviews. Score 0-100 where 0=definitely genuine, 100=definitely fake. Use the full range: 20-40 for suspicious, 50-70 for likely fake, 80+ for obvious fakes. Return JSON: [{"id":"X","score":Y,"explanation":"detailed reason","red_flags":["flag1","flag2"]}]',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.0,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->successful()) {
                LoggingService::log('DeepSeek API request successful');
                $result = $response->json();
                return $this->parseResponse($result, $reviews);
            } else {
                throw new \Exception('DeepSeek API error: ' . $response->status() . ' - ' . $response->body());
            }
            
        } catch (\Exception $e) {
            LoggingService::log('DeepSeek analysis failed: ' . $e->getMessage());
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
            $endpoint = rtrim($this->baseUrl, '/') . '/models';
            $response = Http::timeout(10)->get($endpoint);
            
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
    
    private function buildOptimizedPrompt(array $reviews): string
    {
        $prompt = "Analyze Amazon reviews for authenticity. For each review, provide a score (0-100) and detailed explanation.\n";
        $prompt .= "Return JSON: [{\"id\":\"X\",\"score\":Y,\"explanation\":\"detailed reason\",\"red_flags\":[\"flag1\",\"flag2\"]}]\n\n";
        
        $prompt .= "SCORING GUIDE:\n";
        $prompt .= "HIGH FAKE RISK (70-100): Generic praise, no specifics, promotional language, perfect 5-stars with short text, non-verified purchases, obvious AI writing, repetitive phrases\n";
        $prompt .= "MEDIUM FAKE RISK (40-69): Overly positive without balance, lacks personal context, generic complaints, suspicious timing patterns, limited product knowledge\n";
        $prompt .= "LOW FAKE RISK (20-39): Some specifics but feels coached, minor inconsistencies, unusual language patterns for demographic\n";
        $prompt .= "GENUINE (0-19): Specific details, balanced pros/cons, personal context, natural language, verified purchase, realistic complaints, product knowledge\n\n";
        
        $prompt .= "RED FLAGS TO IDENTIFY:\n";
        $prompt .= "- Generic language (\"amazing product\", \"highly recommend\")\n";
        $prompt .= "- No specific product details or use cases\n";
        $prompt .= "- Overly promotional tone\n";
        $prompt .= "- Perfect ratings with minimal text\n";
        $prompt .= "- Unverified purchase patterns\n";
        $prompt .= "- Repetitive phrases across reviews\n";
        $prompt .= "- Inconsistent language complexity\n";
        $prompt .= "- Suspicious timing or reviewer history\n\n";
        
        foreach ($reviews as $index => $review) {
            $reviewText = is_array($review) ? ($review['text'] ?? '') : $review;
            $reviewId = is_array($review) ? ($review['id'] ?? $index + 1) : $index + 1;
            $rating = is_array($review) ? ($review['rating'] ?? 5) : 5;
            
            // Truncate for efficiency but keep important context
            $truncatedText = strlen($reviewText) > 400 ? substr($reviewText, 0, 400) . '...' : $reviewText;
            $prompt .= "ID:{$reviewId} {$rating}/5\n";
            $prompt .= "Review: \"{$truncatedText}\"\n\n";
        }
        
        return $prompt;
    }
    
    private function parseResponse($response, $reviews): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';
        
        // Parse JSON response similar to OpenAI
        try {
            $scores = json_decode($content, true);
            
            if (!is_array($scores)) {
                throw new \Exception('Invalid JSON response format');
            }
            
            $detailedScores = [];
            foreach ($scores as $scoreData) {
                if (isset($scoreData['id']) && isset($scoreData['score'])) {
                    $detailedScores[] = [
                        'id' => $scoreData['id'],
                        'score' => (float) $scoreData['score'],
                        'explanation' => $scoreData['explanation'] ?? $this->generateExplanation((float)$scoreData['score']),
                        'red_flags' => $scoreData['red_flags'] ?? [],
                        'analysis_details' => [
                            'provider' => 'deepseek',
                            'model' => $this->model,
                            'confidence' => $this->calculateConfidence((float)$scoreData['score'])
                        ]
                    ];
                }
            }
            
            return ['results' => $detailedScores];
            
        } catch (\Exception $e) {
            LoggingService::log('Failed to parse DeepSeek response: ' . $e->getMessage());
            throw new \Exception('Failed to parse DeepSeek response');
        }
    }

    private function generateExplanation(float $score): string
    {
        if ($score >= 70) {
            return "High fake risk: Multiple suspicious indicators detected";
        } elseif ($score >= 40) {
            return "Medium fake risk: Some concerning patterns found";
        } elseif ($score >= 20) {
            return "Low fake risk: Minor inconsistencies noted";
        } else {
            return "Appears genuine: Natural language and specific details";
        }
    }

    private function calculateConfidence(float $score): string
    {
        if ($score >= 80 || $score <= 20) {
            return "high";
        } elseif ($score >= 60 || $score <= 40) {
            return "medium";
        } else {
            return "low";
        }
    }
} 