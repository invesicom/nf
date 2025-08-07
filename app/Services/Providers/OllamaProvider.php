<?php

namespace App\Services\Providers;

use App\Services\LLMProviderInterface;
use App\Services\LoggingService;
use Illuminate\Support\Facades\Http;

class OllamaProvider implements LLMProviderInterface
{
    private string $baseUrl;
    private string $model;
    private int $timeout;
    
    public function __construct()
    {
        $this->baseUrl = config('services.ollama.base_url', 'http://localhost:11434');
        $this->model = config('services.ollama.model', 'llama3.2:3b');
        $this->timeout = config('services.ollama.timeout', 120);
    }
    
    public function analyzeReviews(array $reviews): array
    {
        if (empty($reviews)) {
            return ['results' => []];
        }

        LoggingService::log('Sending '.count($reviews).' reviews to Ollama for analysis');

        $prompt = $this->buildOptimizedPrompt($reviews);
        
        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/api/generate", [
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.3,
                    'num_ctx' => 4096,
                    'top_p' => 0.9
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return $this->parseAnalysisResponse($result['response']);
            }

            throw new \Exception('Ollama API request failed: ' . $response->body());

        } catch (\Exception $e) {
            LoggingService::log('Ollama analysis failed: ' . $e->getMessage());
            throw $e;
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
        return "Ollama-{$this->model}";
    }

    public function getEstimatedCost(int $reviewCount): float
    {
        return 0.0; // Local execution = no cost
    }

    public function getOptimizedMaxTokens(int $reviewCount): int
    {
        // Ollama models are efficient, similar to or better than DeepSeek
        $baseTokens = $reviewCount * 10; // Slightly more conservative than DeepSeek
        $buffer = min(1000, $reviewCount * 5);
        
        return $baseTokens + $buffer;
    }

    private function buildOptimizedPrompt($reviews): string
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
        
        $prompt .= "Key: V=Verified, U=Unverified\n\n";

        foreach ($reviews as $review) {
            $verified = isset($review['meta_data']['verified_purchase']) && $review['meta_data']['verified_purchase'] ? 'V' : 'U';
            
            $text = '';
            if (isset($review['review_text'])) {
                $text = substr($review['review_text'], 0, 400);
            } elseif (isset($review['text'])) {
                $text = substr($review['text'], 0, 400);
            }

            $prompt .= "ID:{$review['id']} {$review['rating']}/5 {$verified}\n";
            $prompt .= "Review: \"{$text}\"\n\n";
        }

        return $prompt;
    }

    private function parseAnalysisResponse(string $response): array
    {
        // Try to extract JSON from the response
        $jsonPattern = '/\[.*?\]/s';
        if (preg_match($jsonPattern, $response, $matches)) {
            $jsonString = $matches[0];
            $decoded = json_decode($jsonString, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $results = [];
                foreach ($decoded as $item) {
                    if (isset($item['id']) && isset($item['score'])) {
                        $results[] = [
                            'id' => $item['id'],
                            'score' => (float)$item['score'],
                            'explanation' => $item['explanation'] ?? $this->generateExplanation((float)$item['score']),
                            'red_flags' => $item['red_flags'] ?? [],
                            'analysis_details' => [
                                'provider' => 'ollama',
                                'model' => $this->model,
                                'confidence' => $this->calculateConfidence((float)$item['score'])
                            ]
                        ];
                    }
                }
                return ['detailed_scores' => $results];
            }
        }

        // Fallback if JSON parsing fails
        LoggingService::log('Failed to parse Ollama JSON response: ' . $response);
        throw new \Exception('Failed to parse Ollama response');
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