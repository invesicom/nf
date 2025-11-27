<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\PromptGenerationService;

class EnhancedPromptGenerationTest extends TestCase
{
    private PromptGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PromptGenerationService();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_includes_paragraph_structure_instructions_in_prompts()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Great product!', 'rating' => 5, 'meta_data' => ['verified_purchase' => true]],
            ['id' => 2, 'text' => 'Not bad', 'rating' => 4, 'meta_data' => ['verified_purchase' => false]],
        ];

        $result = $this->service->generateReviewAnalysisPrompt($reviews, 'single');
        $prompt = $result['prompt'];

        // Should include instructions for structured paragraphs
        $this->assertStringContainsString('3-4 distinct paragraphs', $prompt);
        $this->assertStringContainsString('\\n\\n', $prompt);
        $this->assertStringContainsString('PARAGRAPH 1:', $prompt);
        $this->assertStringContainsString('PARAGRAPH 2:', $prompt);
        $this->assertStringContainsString('PARAGRAPH 3:', $prompt);
        $this->assertStringContainsString('PARAGRAPH 4:', $prompt);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_requests_comprehensive_explanation_content()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Amazing product, highly recommend!', 'rating' => 5, 'meta_data' => ['verified_purchase' => true]],
            ['id' => 2, 'text' => 'Terrible quality, waste of money', 'rating' => 1, 'meta_data' => ['verified_purchase' => true]],
        ];

        $result = $this->service->generateReviewAnalysisPrompt($reviews, 'single');
        $prompt = $result['prompt'];

        // Should request comprehensive analysis
        $this->assertStringContainsString('comprehensive', $prompt);
        $this->assertStringContainsString('3-4 paragraph', $prompt);
        $this->assertStringContainsString('specific review snippets', $prompt);
        $this->assertStringContainsString('detailed findings', $prompt);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_requests_product_insights_for_seo()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Love this wireless headphone, great sound quality', 'rating' => 5, 'meta_data' => ['verified_purchase' => true]],
            ['id' => 2, 'text' => 'Battery life is excellent, comfortable fit', 'rating' => 4, 'meta_data' => ['verified_purchase' => true]],
        ];

        $result = $this->service->generateReviewAnalysisPrompt($reviews, 'single');
        $prompt = $result['prompt'];

        // Should request product insights
        $this->assertStringContainsString('product_insights', $prompt);
        $this->assertStringContainsString('product', $prompt);
        $this->assertStringContainsString('2-3 sentence', $prompt);
        $this->assertStringContainsString('genuine reviews', $prompt);
        $this->assertStringContainsString('avoiding direct copying', $prompt);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maintains_json_response_format_requirements()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Good product overall', 'rating' => 4, 'meta_data' => ['verified_purchase' => true]],
        ];

        $result = $this->service->generateReviewAnalysisPrompt($reviews, 'single');
        $prompt = $result['prompt'];

        // Should maintain all required JSON fields
        $this->assertStringContainsString('"fake_percentage":', $prompt);
        $this->assertStringContainsString('"confidence":', $prompt);
        $this->assertStringContainsString('"explanation":', $prompt);
        $this->assertStringContainsString('"product_insights":', $prompt);
        $this->assertStringContainsString('"fake_examples":', $prompt);
        $this->assertStringContainsString('"key_patterns":', $prompt);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_includes_formatting_importance_emphasis()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Test review', 'rating' => 3, 'meta_data' => ['verified_purchase' => true]],
        ];

        $result = $this->service->generateReviewAnalysisPrompt($reviews, 'single');
        $prompt = $result['prompt'];

        // Should emphasize formatting importance
        $this->assertStringContainsString('IMPORTANT: Separate each paragraph', $prompt);
        $this->assertStringContainsString('double line breaks', $prompt);
        $this->assertStringContainsString('proper formatting', $prompt);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_works_with_chat_format_prompts()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Chat format test', 'rating' => 4, 'meta_data' => ['verified_purchase' => true]],
        ];

        $chatResult = $this->service->generateReviewAnalysisPrompt($reviews, 'chat');

        $this->assertIsArray($chatResult);
        $this->assertArrayHasKey('system', $chatResult);
        $this->assertArrayHasKey('user', $chatResult);
        
        // Should include paragraph formatting instructions in system message
        $this->assertStringContainsString('3-4 distinct paragraphs', $chatResult['user']);
        $this->assertStringContainsString('product_insights', $chatResult['user']);
    }
}
