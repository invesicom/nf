<?php

require_once 'vendor/autoload.php';

use App\Services\Providers\DeepSeekProvider;
use App\Services\PromptGenerationService;
use Illuminate\Support\Facades\Http;

// Sample reviews from the Chrome extension payload (first 5 for testing)
$sampleReviews = [
    [
        'id' => 'R3P5F46I6I00ND',
        'review_text' => 'The simple, wholesome ingredients create a creamy, cheesy sauce that perfectly coats the tender pasta shells. It\'s a quick and easy meal that appeals to both kids and adults, offering a nostalgic taste of childhood with every bite. While it might not be gourmet, Annie\'s provides a reliable and satisfying mac and cheese experience that I wholeheartedly recommend.',
        'rating' => 5,
        'meta_data' => ['verified_purchase' => true],
    ],
    [
        'id' => 'R17EXK6FX6VA7D',
        'review_text' => 'Great tasting!',
        'rating' => 5,
        'meta_data' => ['verified_purchase' => true],
    ],
    [
        'id' => 'R1RZ8PQX6M71Z2',
        'review_text' => 'Delicious! We love Annie\'s Mac and cheese! Soooooooo much better than KD',
        'rating' => 5,
        'meta_data' => ['verified_purchase' => true],
    ],
    [
        'id' => 'R3DYCCOHP55N4Y',
        'review_text' => 'This is very good macaroni and cheese. There is a sharp cheddar flavour that is very appetizing. I enjoy the flavour more then kraft dinner because although it is not a huge difference in taste, this product has a more real and authentic cheddar cheese taste.',
        'rating' => 4,
        'meta_data' => ['verified_purchase' => true],
    ],
    [
        'id' => 'R25LE69UM8YV9Z',
        'review_text' => 'This definitely has a milder cheese flavour than some of the other brands, but considering it\'s mostly organic, I don\'t mind at all. The boxes that I received were all in very good condition, and the best before dates are at the end of 2025!',
        'rating' => 5,
        'meta_data' => ['verified_purchase' => true],
    ],
];

echo "=== DEEPSEEK DEBUG TEST ===\n";
echo "Testing with " . count($sampleReviews) . " sample reviews\n\n";

// Generate the prompt using our centralized service
$promptData = PromptGenerationService::generateReviewAnalysisPrompt(
    $sampleReviews,
    'chat', // DeepSeek uses chat format
    PromptGenerationService::getProviderTextLimit('deepseek')
);

echo "=== GENERATED PROMPT ===\n";
echo "System Message:\n" . $promptData['system'] . "\n\n";
echo "User Message:\n" . $promptData['user'] . "\n\n";

// Check if we have DeepSeek API key
$apiKey = env('DEEPSEEK_API_KEY');
if (empty($apiKey)) {
    echo "❌ DEEPSEEK_API_KEY not configured. Cannot make real API request.\n";
    echo "Please set DEEPSEEK_API_KEY in your .env file to test with real API.\n";
    exit(1);
}

echo "=== MAKING DEEPSEEK API REQUEST ===\n";

try {
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type'  => 'application/json',
        'User-Agent'    => 'ReviewAnalyzer-DeepSeek-Debug/1.0',
    ])->timeout(120)->post('https://api.deepseek.com/v1/chat/completions', [
        'model'    => 'deepseek-chat', // Using the model from your logs
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
        'max_tokens'  => 1000, // Sufficient for 5 reviews
    ]);

    if ($response->successful()) {
        $result = $response->json();
        $content = $result['choices'][0]['message']['content'] ?? '';
        
        echo "✅ API Request successful!\n";
        echo "Response length: " . strlen($content) . " characters\n\n";
        echo "=== RAW RESPONSE CONTENT ===\n";
        echo $content . "\n\n";
        
        echo "=== JSON PARSING TEST ===\n";
        
        // Test direct JSON decode
        $scores = json_decode($content, true);
        if (is_array($scores)) {
            echo "✅ Direct JSON decode successful!\n";
            echo "Parsed array: " . json_encode($scores, JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "❌ Direct JSON decode failed. Error: " . json_last_error_msg() . "\n";
            
            // Try enhanced extraction patterns
            echo "Trying enhanced extraction patterns...\n";
            
            // Try markdown code blocks
            if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/s', $content, $matches)) {
                echo "Found JSON in markdown code block\n";
                $scores = json_decode($matches[1], true);
                if (is_array($scores)) {
                    echo "✅ Markdown extraction successful!\n";
                    echo "Parsed array: " . json_encode($scores, JSON_PRETTY_PRINT) . "\n";
                } else {
                    echo "❌ Markdown extraction failed. Error: " . json_last_error_msg() . "\n";
                }
            }
            // Try JSON array pattern
            elseif (preg_match('/(\[(?:[^[\]]+|(?1))*\])/', $content, $matches)) {
                echo "Found JSON array pattern\n";
                $scores = json_decode($matches[1], true);
                if (is_array($scores)) {
                    echo "✅ Array pattern extraction successful!\n";
                    echo "Parsed array: " . json_encode($scores, JSON_PRETTY_PRINT) . "\n";
                } else {
                    echo "❌ Array pattern extraction failed. Error: " . json_last_error_msg() . "\n";
                }
            }
            // Try full content extraction
            elseif (preg_match('/^.*?(\[.*\]).*?$/s', $content, $matches)) {
                echo "Attempting full content extraction\n";
                $scores = json_decode($matches[1], true);
                if (is_array($scores)) {
                    echo "✅ Full content extraction successful!\n";
                    echo "Parsed array: " . json_encode($scores, JSON_PRETTY_PRINT) . "\n";
                } else {
                    echo "❌ Full content extraction failed. Error: " . json_last_error_msg() . "\n";
                }
            } else {
                echo "❌ No JSON patterns found in response\n";
            }
        }
        
    } else {
        echo "❌ API Request failed!\n";
        echo "Status: " . $response->status() . "\n";
        echo "Body: " . $response->body() . "\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Exception occurred: " . $e->getMessage() . "\n";
}

echo "\n=== DEBUG COMPLETE ===\n";
