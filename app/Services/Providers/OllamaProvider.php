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
        $this->model = config('services.ollama.model') ?: 'llama3.2:3b'; // Use lighter model for better performance
        $this->timeout = config('services.ollama.timeout', 120); // Reduced timeout to prevent hanging
    }
    
    public function analyzeReviews(array $reviews): array
    {
        if (empty($reviews)) {
            return ['results' => []];
        }

        // PERFORMANCE: Use chunking for large review sets to dramatically reduce processing time
        if (count($reviews) > 10) {
            return $this->analyzeReviewsInChunks($reviews);
        }

        LoggingService::log('Sending '.count($reviews).' reviews to Ollama for analysis');

        $prompt = $this->buildOptimizedPrompt($reviews);
        
        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/api/generate", [
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.1, // Lower temperature for more consistent, less aggressive scoring
                    'num_ctx' => 4096,
                    'top_p' => 0.8 // Slightly more focused responses
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

    /**
     * Analyze reviews in parallel chunks for better performance
     */
    private function analyzeReviewsInChunks(array $reviews): array
    {
        $chunkSize = 8; // Optimal chunk size based on testing
        $chunks = array_chunk($reviews, $chunkSize);
        
        LoggingService::log('Processing '.count($reviews).' reviews in '.count($chunks).' chunks of '.$chunkSize.' for performance');
        
        try {
            // Process chunks in parallel using Http::pool
            $responses = Http::pool(function ($pool) use ($chunks) {
                foreach ($chunks as $chunk) {
                    $prompt = $this->buildOptimizedPrompt($chunk);
                    $pool->timeout($this->timeout)->post("{$this->baseUrl}/api/generate", [
                        'model' => $this->model,
                        'prompt' => $prompt,
                        'stream' => false,
                        'options' => [
                            'temperature' => 0.1,
                            'num_ctx' => 4096,
                            'top_p' => 0.8
                        ]
                    ]);
                }
            });

            // Combine results from all chunks
            $allResults = [];
            $successfulChunks = 0;
            
            foreach ($responses as $index => $response) {
                if ($response instanceof \Exception) {
                    LoggingService::log('Chunk '.($index + 1).' failed with exception: '.$response->getMessage());
                    continue;
                }
                
                if ($response->successful()) {
                    $chunkResults = $this->parseAnalysisResponse($response->json()['response']);
                    if (isset($chunkResults['detailed_scores'])) {
                        $allResults = array_merge($allResults, $chunkResults['detailed_scores']);
                    }
                    $successfulChunks++;
                } else {
                    LoggingService::log('Chunk '.($index + 1).' failed with status: '.$response->status());
                }
            }

            LoggingService::log('Chunked analysis completed: '.$successfulChunks.'/'.count($chunks).' chunks successful');

            return [
                'detailed_scores' => $allResults,
                'analysis_provider' => $this->getProviderName(),
                'total_cost' => 0.0,
                'chunks_processed' => $successfulChunks,
                'total_chunks' => count($chunks)
            ];

        } catch (\Exception $e) {
            LoggingService::log('Chunked analysis failed: ' . $e->getMessage());
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
        // RESEARCH-BASED but OPTIMIZED: Scientific methodology with resource efficiency
        $prompt = "Marketplace integrity analyst. Score 0-100 (0=genuine, 100=fake) using scientific methodology.\n\n";
        
        $prompt .= "METHOD: Start S=50, apply bounded adjustments:\n";
        $prompt .= "• Generic/promotional tone: +15-25\n";
        $prompt .= "• Specific details/complaints: -15-25\n";
        $prompt .= "• Unverified purchase: +10, Verified: -5\n";
        $prompt .= "• Rating-text mismatch: +15\n\n";
        
        $prompt .= "LABELS: genuine ≤39, uncertain 40-59, fake ≥60\n";
        $prompt .= "BIAS GUARDRAILS: Don't penalize non-native writing or brevity alone\n";
        $prompt .= "NEGATIVE REVIEWS: Detailed complaints are AUTHENTIC\n\n";
        
        $prompt .= "Return JSON: [{\"id\":\"X\",\"score\":Y,\"label\":\"genuine|uncertain|fake\",\"confidence\":Z}]\n\n";
        $prompt .= "Key: V=Verified, U=Unverified\n\n";

        foreach ($reviews as $review) {
            $verified = isset($review['meta_data']['verified_purchase']) && $review['meta_data']['verified_purchase'] ? 'V' : 'U';
            
            $text = '';
            if (isset($review['review_text'])) {
                $text = substr($review['review_text'], 0, 300); // Reduced from 400 to 300
            } elseif (isset($review['text'])) {
                $text = substr($review['text'], 0, 300);
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
                $score = max(0, min(100, (int)$item['score'])); // Clamp to 0-100
                
                // Support new research-based format with additional metadata
                $label = $item['label'] ?? $this->generateLabel($score);
                $confidence = isset($item['confidence']) ? (float)$item['confidence'] : $this->calculateConfidenceFromScore($score);
                
                $results[$item['id']] = [
                    'score' => $score,
                    'label' => $label,
                    'confidence' => $confidence,
                    'explanation' => $this->generateExplanationFromLabel($label, $score)
                ];
            }
        }
        return [
            'detailed_scores' => $results,
            'analysis_provider' => $this->getProviderName(),
            'total_cost' => 0.0
        ];
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
        
        return [
            'detailed_scores' => $results,
            'analysis_provider' => $this->getProviderName(),
            'total_cost' => 0.0
        ];
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

    private function generateLabel(float $score): string
    {
        if ($score <= 39) {
            return 'genuine';
        } elseif ($score <= 59) {
            return 'uncertain';
        } else {
            return 'fake';
        }
    }

    private function calculateConfidenceFromScore(float $score): float
    {
        // Map score to confidence based on research methodology
        if ($score <= 20 || $score >= 80) {
            return 0.8; // High confidence for extreme scores
        } elseif ($score <= 30 || $score >= 70) {
            return 0.7; // Good confidence
        } elseif ($score <= 40 || $score >= 60) {
            return 0.6; // Moderate confidence
        } else {
            return 0.4; // Lower confidence for uncertain range
        }
    }

    private function generateExplanationFromLabel(string $label, float $score): string
    {
        switch ($label) {
            case 'genuine':
                return "Appears authentic: Contains specific details, balanced perspective, or credible context (Score: {$score})";
            case 'uncertain':
                return "Mixed signals: Some concerning patterns but insufficient evidence for definitive classification (Score: {$score})";
            case 'fake':
                return "High fake risk: Multiple suspicious indicators detected using forensic-linguistic analysis (Score: {$score})";
            default:
                return "Analysis completed using research-based methodology (Score: {$score})";
        }
    }

    private function extractRedFlagsFromScore(float $score): array
    {
        $flags = [];
        
        if ($score >= 70) {
            $flags[] = 'High fake probability';
        }
        if ($score >= 80) {
            $flags[] = 'Multiple suspicious indicators';
        }
        if ($score >= 90) {
            $flags[] = 'Extremely high fake risk';
        }
        
        return $flags;
    }
}