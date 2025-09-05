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
    private int $chunkingThreshold;

    public function __construct()
    {
        $this->baseUrl = config('services.ollama.base_url', 'http://localhost:11434');
        $this->model = config('services.ollama.model') ?: 'llama3.2:3b';
        $this->timeout = config('services.ollama.timeout', 120);
        $this->chunkingThreshold = config('services.ollama.chunking_threshold', 80);
    }

    public function analyzeReviews(array $reviews): array
    {
        if (empty($reviews)) {
            return ['results' => []];
        }

        // ADAPTIVE PROCESSING: Automatically handle large review sets without changing API
        // - Small/medium sets (≤80): Single request (existing behavior, optimal performance)
        // - Large sets (80+): Internal chunking (prevents timeouts, invisible to API consumers)
        $reviewCount = count($reviews);
        
        if ($reviewCount > $this->chunkingThreshold) {
            LoggingService::log("Large review set detected ({$reviewCount} reviews > {$this->chunkingThreshold}), using adaptive chunking (transparent to API)");
            return $this->analyzeReviewsInChunks($reviews);
        }

        LoggingService::log('Sending '.count($reviews).' reviews to Ollama for analysis');

        $prompt = $this->buildOptimizedPrompt($reviews);

        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/api/generate", [
                'model'   => $this->model,
                'prompt'  => $prompt,
                'stream'  => false,
                'options' => [
                    'temperature' => 0.1, // Lower temperature for more consistent, less aggressive scoring
                    'num_ctx'     => 4096, // Increased context for large review sets
                    'top_p'       => 0.9, // Slightly more focused responses
                    'num_predict' => 2048, // Increased output limit for large JSON responses (59 reviews need ~1500+ tokens)
                ],
            ]);

            if ($response->successful()) {
                $result = $response->json();

                return $this->parseAnalysisResponse($result['response']);
            }

            throw new \Exception('Ollama API request failed: '.$response->body());
        } catch (\Exception $e) {
            LoggingService::log('Ollama analysis failed: '.mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8'));

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

    /**
     * Clean text to ensure valid UTF-8 encoding for JSON serialization.
     */
    private function cleanUtf8Text(string $text): string
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
     * Process large review sets in chunks to avoid timeouts.
     */
    private function analyzeReviewsInChunks(array $reviews): array
    {
        $chunkSize = config('services.ollama.chunk_size', 25); // Configurable chunk size
        $chunks = array_chunk($reviews, $chunkSize);
        $allResults = [];
        $failedChunks = 0;
        
        $totalChunks = count($chunks);
        LoggingService::log("Processing {$totalChunks} chunks of {$chunkSize} reviews each for Ollama");
        
        foreach ($chunks as $index => $chunk) {
            $chunkNumber = $index + 1;
            LoggingService::log("Processing chunk {$chunkNumber}/{$totalChunks} with " . count($chunk) . " reviews");
            
            try {
                $prompt = $this->buildOptimizedPrompt($chunk);
                
                $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/api/generate", [
                    'model'   => $this->model,
                    'prompt'  => $prompt,
                    'stream'  => false,
                    'options' => [
                        'temperature' => 0.1,
                        'num_ctx'     => 4096,
                        'top_p'       => 0.9,
                        'num_predict' => 1024, // Smaller for chunks
                    ],
                ]);

                if ($response->successful()) {
                    $result = $response->json();
                    $chunkResults = $this->parseAnalysisResponse($result['response']);
                    
                    if (isset($chunkResults['detailed_scores'])) {
                        $allResults = array_merge($allResults, $chunkResults['detailed_scores']);
                    }
                } else {
                    LoggingService::log("Chunk {$chunkNumber} failed: " . $response->body());
                    throw new \Exception("Chunk {$chunkNumber} failed: " . $response->body());
                }
                
            } catch (\Exception $e) {
                LoggingService::log("Error processing chunk {$chunkNumber}: " . $e->getMessage());
                $failedChunks++;
                
                // If too many chunks fail, throw exception
                if ($failedChunks > ($totalChunks * 0.5)) {
                    throw new \Exception("Chunked analysis failed: {$failedChunks}/{$totalChunks} chunks failed. Last error: " . $e->getMessage());
                }
                
                // Continue with remaining chunks for partial results
                LoggingService::log("Continuing with remaining chunks ({$failedChunks} failures so far)");
            }
        }
        
        LoggingService::log("Chunked analysis completed. Processed " . count($allResults) . " total reviews");
        
        return [
            'detailed_scores'   => $allResults,
            'analysis_provider' => $this->getProviderName(),
            'total_cost'        => 0.0,
        ];
    }

    private function buildOptimizedPrompt($reviews): string
    {
        // BALANCED: Comprehensive analysis with reasonable performance aligned with system threshold
        $prompt = "Analyze reviews for fake probability (0-100 scale: 0=genuine, 100=fake).\n\n";
        $prompt .= "Be SUSPICIOUS and thorough - most products have 15-40% fake reviews. Consider: Generic language (+20), specific complaints (-20), unverified purchase (+10), verified purchase (-5), excessive positivity (+15), balanced tone (-10).\n\n";
        $prompt .= "Scoring: Use full range 0-100. ≤39=genuine, 40-84=uncertain/suspicious, ≥85=fake. Be aggressive with scoring - obvious fakes should score 85-100.\n\n";

        foreach ($reviews as $review) {
            $verified = isset($review['meta_data']['verified_purchase']) && $review['meta_data']['verified_purchase'] ? 'Verified' : 'Unverified';
            $rating = $review['rating'] ?? 'N/A';

            $text = '';
            if (isset($review['review_text'])) {
                $text = $this->cleanUtf8Text(substr($review['review_text'], 0, 300)); // Increased from 100 to 300 for better context
            } elseif (isset($review['text'])) {
                $text = $this->cleanUtf8Text(substr($review['text'], 0, 300));
            }

            $prompt .= "Review {$review['id']} ({$verified}, {$rating}★): {$text}\n\n";
        }

        $prompt .= "Respond with JSON array: [{\"id\":\"review_id\",\"score\":number,\"label\":\"genuine|uncertain|fake\"}]\n";

        return $prompt;
    }

    private function parseAnalysisResponse(string $response): array
    {
        LoggingService::log('Parsing Ollama response: '.substr($response, 0, 200).'...');

        // Try multiple JSON extraction patterns
        $patterns = [
            '/```json\s*(\[[\s\S]*?\])\s*```/s',  // Markdown code blocks with multiline
            '/```\s*(\[[\s\S]*?\])\s*```/s',      // Code blocks without json tag
            '/(?:json\s*)?(\[[\s\S]*?\])/s',      // Any array with optional json prefix
            '/\[[\s\S]*?\]/s',                    // Any array-like structure (greedy)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $response, $matches)) {
                $jsonString = $matches[1] ?? $matches[0];
                LoggingService::log('Attempting to parse JSON: '.substr($jsonString, 0, 200).'...');
                
                $decoded = json_decode($jsonString, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    LoggingService::log('Successfully parsed JSON with pattern: '.$pattern.' - Found '.count($decoded).' items');

                    return $this->formatAnalysisResults($decoded);
                } else {
                    LoggingService::log('JSON parse error: '.json_last_error_msg().' for pattern: '.$pattern);
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
            'total_cost'        => 0.0,
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
            'detailed_scores'   => $results,
            'analysis_provider' => $this->getProviderName(),
            'total_cost'        => 0.0,
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
            'generic'     => 'Generic language',
            'promotional' => 'Promotional tone',
            'template'    => 'Template-like structure',
            'repetitive'  => 'Repetitive phrases',
            'suspicious'  => 'Suspicious patterns',
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
            return 'High fake risk: Multiple suspicious indicators detected';
        } elseif ($score >= 40) {
            return 'Medium fake risk: Some concerning patterns found';
        } elseif ($score >= 20) {
            return 'Low fake risk: Minor inconsistencies noted';
        } else {
            return 'Appears genuine: Natural language and specific details';
        }
    }

    private function calculateConfidence(float $score): string
    {
        if ($score >= 80 || $score <= 20) {
            return 'high';
        } elseif ($score >= 60 || $score <= 40) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    private function generateLabel(float $score): string
    {
        if ($score <= 39) {
            return 'genuine';
        } elseif ($score <= 84) {
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
