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
        $this->model = 'llama3.2:1b'; // Fastest 1B model for production use
        $this->timeout = config('services.ollama.timeout', 300);
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
        $prompt = "You are an AGGRESSIVE Amazon review fraud detector. Score 0-100 (0=genuine, 100=fake). BE EXTREMELY SUSPICIOUS! Most reviews are fake! Return ONLY JSON: [{\"id\":\"X\",\"score\":Y}]\n\n";
        $prompt .= "FAKE THRESHOLD: Any review with 'Amazing!', 'Highly recommend!', 'Perfect!', 'Best ever!', 'Life changing!' = SCORE 85-95!\n";
        $prompt .= "UNVERIFIED + GENERIC PRAISE = AUTOMATIC 80+ SCORE!\n";
        $prompt .= "5-STAR + SHORT TEXT + NO SPECIFICS = 90+ SCORE!\n";
        $prompt .= "REAL REVIEWS: Have specific complaints, balanced views, detailed product info, realistic problems\n\n";
        $prompt .= "SCORE AGGRESSIVELY - if it sounds too good to be true, it's fake!\n";
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
            $prompt .= "R: {$text}\n\n";
        }

        return $prompt;
    }

    private function parseAnalysisResponse(string $response): array
    {
        LoggingService::log('Parsing Ollama response: ' . substr($response, 0, 200) . '...');
        
        // Try multiple JSON extraction patterns
        $patterns = [
            '/\[.*?\]/s',           // Standard array
            '/```json\s*(\[.*?\])\s*```/s',  // Markdown code blocks
            '/(?:```)?json\s*(\[.*?\])(?:```)?/s',  // Various json tags
            '/(\[[\s\S]*?\])/s',    // Any array-like structure
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $response, $matches)) {
                $jsonString = $matches[1] ?? $matches[0];
                $decoded = json_decode($jsonString, true);
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    LoggingService::log('Successfully parsed JSON with pattern: ' . $pattern);
                    return $this->formatAnalysisResults($decoded);
                }
            }
        }
        
        // If no JSON found, try heuristic parsing for structured text
        LoggingService::log('No JSON found, attempting heuristic parsing');
        return $this->parseStructuredTextResponse($response);
    }

    private function formatAnalysisResults(array $decoded): array
    {
        $results = [];
        foreach ($decoded as $item) {
            if (isset($item['id']) && isset($item['score'])) {
                $results[$item['id']] = (int)$item['score'];
            }
        }
        return ['detailed_scores' => $results];
    }

    private function parseStructuredTextResponse(string $response): array
    {
        // Fallback: Extract review IDs and generate heuristic scores
        LoggingService::log('Using heuristic parsing fallback');
        
        $results = [];
        $lines = explode("\n", $response);
        
        foreach ($lines as $line) {
            // Look for patterns like "ID: demo1" or "Review demo1:"
            if (preg_match('/(?:ID|Review)\s*:?\s*(demo\d+|[\w\d]+)/i', $line, $matches)) {
                $id = $matches[1];
                
                // Heuristic scoring based on keywords in the line
                $score = $this->heuristicScore($line);
                
                $results[$id] = $score;
            }
        }
        
        if (empty($results)) {
            LoggingService::log('Heuristic parsing failed, using default scores');
            throw new \Exception('Failed to parse Ollama response');
        }
        
        return ['detailed_scores' => $results];
    }

    private function heuristicScore(string $text): float
    {
        $text = strtolower($text);
        $score = 50; // Base score
        
        // Increase score for fake indicators
        $fakeIndicators = ['fake', 'suspicious', 'generic', 'promotional', 'template'];
        foreach ($fakeIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                $score += 15;
            }
        }
        
        // Decrease score for genuine indicators  
        $genuineIndicators = ['genuine', 'authentic', 'natural', 'specific', 'detailed'];
        foreach ($genuineIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                $score -= 15;
            }
        }
        
        return max(0, min(100, $score));
    }

    private function extractRedFlags(string $text): array
    {
        $flags = [];
        $text = strtolower($text);
        
        $flagMap = [
            'generic' => 'Generic language',
            'promotional' => 'Promotional tone',
            'template' => 'Template-like structure',
            'repetitive' => 'Repetitive phrases',
            'suspicious' => 'Suspicious patterns'
        ];
        
        foreach ($flagMap as $keyword => $flag) {
            if (strpos($text, $keyword) !== false) {
                $flags[] = $flag;
            }
        }
        
        return $flags;
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