<?php

namespace Tests\Unit;

use App\Services\PromptGenerationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PromptGenerationServiceTest extends TestCase
{
    private array $sampleReviews;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->sampleReviews = [
            [
                'id' => 'R1',
                'review_text' => 'Great product, works as expected. Verified purchase.',
                'rating' => 5,
                'meta_data' => ['verified_purchase' => true],
            ],
            [
                'id' => 'R2',
                'review_text' => 'Not what I expected, poor quality materials.',
                'rating' => 2,
                'meta_data' => ['verified_purchase' => false],
            ],
        ];
    }

    #[Test]
    public function it_generates_single_prompt_format()
    {
        $result = PromptGenerationService::generateReviewAnalysisPrompt(
            $this->sampleReviews,
            'single',
            300
        );

        $this->assertArrayHasKey('prompt', $result);
        $this->assertStringContainsString('Analyze reviews for fake probability', $result['prompt']);
        $this->assertStringContainsString('Review R1 (Verified, 5★)', $result['prompt']);
        $this->assertStringContainsString('Review R2 (Unverified, 2★)', $result['prompt']);
        $this->assertStringContainsString('JSON array', $result['prompt']);
    }

    #[Test]
    public function it_generates_chat_prompt_format()
    {
        $result = PromptGenerationService::generateReviewAnalysisPrompt(
            $this->sampleReviews,
            'chat',
            300
        );

        $this->assertArrayHasKey('system', $result);
        $this->assertArrayHasKey('user', $result);
        $this->assertStringContainsString('Analyze reviews for fake probability', $result['system']);
        $this->assertStringContainsString('Review R1 (Verified, 5★)', $result['user']);
        $this->assertStringContainsString('JSON array', $result['user']);
    }

    #[Test]
    public function it_respects_text_length_limits()
    {
        $longReview = [
            'id' => 'R3',
            'review_text' => str_repeat('This is a very long review text. ', 50), // ~1500 chars
            'rating' => 4,
            'meta_data' => ['verified_purchase' => true],
        ];

        $result = PromptGenerationService::generateReviewAnalysisPrompt(
            [$longReview],
            'single',
            100 // Short limit
        );

        // Should be truncated to ~100 chars
        $this->assertStringContainsString('Review R3', $result['prompt']);
        // The review text should be truncated (original was ~1500 chars, should be ~100)
        $originalLength = strlen(str_repeat('This is a very long review text. ', 50));
        $promptLength = strlen($result['prompt']);
        // Prompt should be much shorter than if we included the full review text
        $this->assertLessThan($originalLength + 500, $promptLength); // Much shorter than original + overhead
    }

    #[Test]
    public function it_handles_missing_review_text_gracefully()
    {
        $reviewWithoutText = [
            'id' => 'R4',
            'rating' => 3,
            'meta_data' => ['verified_purchase' => true],
        ];

        $result = PromptGenerationService::generateReviewAnalysisPrompt(
            [$reviewWithoutText],
            'single',
            300
        );

        $this->assertStringContainsString('Review R4 (Verified, 3★)', $result['prompt']);
    }

    #[Test]
    public function it_handles_alternative_text_field()
    {
        $reviewWithTextField = [
            'id' => 'R5',
            'text' => 'Review using text field instead of review_text',
            'rating' => 4,
            'meta_data' => ['verified_purchase' => false],
        ];

        $result = PromptGenerationService::generateReviewAnalysisPrompt(
            [$reviewWithTextField],
            'single',
            300
        );

        $this->assertStringContainsString('Review R5 (Unverified, 4★)', $result['prompt']);
        $this->assertStringContainsString('text field instead', $result['prompt']);
    }

    #[Test]
    public function it_provides_consistent_core_instructions()
    {
        $result1 = PromptGenerationService::generateReviewAnalysisPrompt($this->sampleReviews, 'single');
        $result2 = PromptGenerationService::generateReviewAnalysisPrompt($this->sampleReviews, 'chat');

        // Both formats should contain the same core scoring guidance
        $this->assertStringContainsString('≤39=genuine', $result1['prompt']);
        $this->assertStringContainsString('≥85=fake', $result1['prompt']);
        $this->assertStringContainsString('≤39=genuine', $result2['system']);
        $this->assertStringContainsString('≥85=fake', $result2['system']);
    }

    #[Test]
    public function it_gets_provider_specific_text_limits()
    {
        $this->assertEquals(400, PromptGenerationService::getProviderTextLimit('openai'));
        $this->assertEquals(300, PromptGenerationService::getProviderTextLimit('deepseek'));
        $this->assertEquals(300, PromptGenerationService::getProviderTextLimit('ollama'));
        $this->assertEquals(300, PromptGenerationService::getProviderTextLimit('unknown'));
    }

    #[Test]
    public function it_gets_provider_specific_system_messages()
    {
        $openaiMessage = PromptGenerationService::getProviderSystemMessage('openai');
        $deepseekMessage = PromptGenerationService::getProviderSystemMessage('deepseek');
        $ollamaMessage = PromptGenerationService::getProviderSystemMessage('ollama');

        // All should contain core elements
        $this->assertStringContainsString('expert Amazon review authenticity detector', $openaiMessage);
        $this->assertStringContainsString('expert Amazon review authenticity detector', $deepseekMessage);
        $this->assertStringContainsString('expert Amazon review authenticity detector', $ollamaMessage);
        
        $this->assertStringContainsString('15-40% fake reviews', $openaiMessage);
        $this->assertStringContainsString('JSON', $openaiMessage);
    }

    #[Test]
    public function it_validates_reviews_structure_correctly()
    {
        // Valid structure
        $this->assertTrue(PromptGenerationService::validateReviewsStructure($this->sampleReviews));

        // Missing id
        $invalidReviews = [
            ['review_text' => 'Text without id', 'rating' => 5]
        ];
        $this->assertFalse(PromptGenerationService::validateReviewsStructure($invalidReviews));

        // Missing text fields
        $invalidReviews2 = [
            ['id' => 'R1', 'rating' => 5]
        ];
        $this->assertFalse(PromptGenerationService::validateReviewsStructure($invalidReviews2));

        // Empty array
        $this->assertFalse(PromptGenerationService::validateReviewsStructure([]));
    }

    #[Test]
    public function it_cleans_utf8_text_properly()
    {
        $reviewWithBadUtf8 = [
            'id' => 'R6',
            'review_text' => "Good product\x00\x1A with null bytes",
            'rating' => 4,
            'meta_data' => ['verified_purchase' => true],
        ];

        $result = PromptGenerationService::generateReviewAnalysisPrompt(
            [$reviewWithBadUtf8],
            'single',
            300
        );

        // Should not contain null bytes
        $this->assertStringNotContainsString("\x00", $result['prompt']);
        $this->assertStringNotContainsString("\x1A", $result['prompt']);
        $this->assertStringContainsString('Good product', $result['prompt']);
    }

    #[Test]
    public function it_handles_missing_meta_data_gracefully()
    {
        $reviewWithoutMetaData = [
            'id' => 'R7',
            'review_text' => 'Review without meta_data',
            'rating' => 3,
        ];

        $result = PromptGenerationService::generateReviewAnalysisPrompt(
            [$reviewWithoutMetaData],
            'single',
            300
        );

        $this->assertStringContainsString('Review R7 (Unverified, 3★)', $result['prompt']);
    }

    #[Test]
    public function it_handles_missing_rating_gracefully()
    {
        $reviewWithoutRating = [
            'id' => 'R8',
            'review_text' => 'Review without rating',
            'meta_data' => ['verified_purchase' => true],
        ];

        $result = PromptGenerationService::generateReviewAnalysisPrompt(
            [$reviewWithoutRating],
            'single',
            300
        );

        $this->assertStringContainsString('Review R8 (Verified, N/A★)', $result['prompt']);
    }
}
