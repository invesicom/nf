<?php

namespace Tests\Feature;

use App\Models\AsinData;
use App\Services\Providers\OllamaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FakeDetectionAnalysisCommandsTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function analyze_fake_detection_command_processes_recent_data()
    {
        // Create test data with analysis results
        $asinData = AsinData::factory()->create([
            'asin' => 'B0TEST001',
            'country' => 'us',
            'fake_percentage' => 75.5,
            'openai_result' => json_encode([
                'detailed_scores' => [
                    'R001' => 85,
                    'R002' => 90,
                    'R003' => 25,
                    'R004' => 80,
                    'R005' => 15
                ],
                'analysis_provider' => 'Ollama-qwen2.5:7b',
                'total_cost' => 0.0
            ])
        ]);

        $this->artisan('analyze:fake-detection', ['--limit' => 10])
            ->expectsOutputToContain('Analyzing last 10 review analyses for fake detection patterns...')
            ->expectsOutputToContain('Found 1 analyses to examine')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function analyze_fake_detection_handles_new_score_format()
    {
        // Create test data with new research-based score format
        $asinData = AsinData::factory()->create([
            'asin' => 'B0TEST002',
            'country' => 'us',
            'fake_percentage' => 45.0,
            'openai_result' => json_encode([
                'detailed_scores' => [
                    'R001' => [
                        'score' => 35,
                        'label' => 'genuine',
                        'confidence' => 0.8,
                        'explanation' => 'Appears authentic with specific details'
                    ],
                    'R002' => [
                        'score' => 75,
                        'label' => 'fake',
                        'confidence' => 0.9,
                        'explanation' => 'High fake risk detected'
                    ]
                ],
                'analysis_provider' => 'Ollama-qwen2.5:7b',
                'total_cost' => 0.0
            ])
        ]);

        $this->artisan('analyze:fake-detection', ['--limit' => 5])
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_new_scoring_command_works_with_sample_data()
    {
        Http::fake([
            'localhost:11434/api/tags' => Http::response(['models' => []], 200),
            'localhost:11434/api/generate' => Http::response([
                'model' => 'qwen2.5:7b',
                'response' => '[{"id":"TEST001","score":85,"label":"fake","confidence":0.9},{"id":"TEST002","score":35,"label":"genuine","confidence":0.8}]',
                'done' => true,
            ])
        ]);

        $this->artisan('test:new-scoring')
            ->expectsOutputToContain('Testing new scoring system with sample reviews...')
            ->expectsOutputToContain('Provider: Ollama-qwen2.5:7b')
            ->expectsOutputToContain('Analyzing 5 reviews...')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_new_scoring_command_handles_ollama_unavailable()
    {
        Http::fake([
            'localhost:11434/api/tags' => Http::response('', 500)
        ]);

        $this->artisan('test:new-scoring')
            ->expectsOutput('OLLAMA service is not available. Please ensure it is running.')
            ->assertExitCode(1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_new_scoring_command_with_real_asin()
    {
        // Create test ASIN data
        $asinData = AsinData::factory()->create([
            'asin' => 'B0TESTREAL',
            'country' => 'us',
            'reviews' => json_encode([
                [
                    'id' => 'R001',
                    'rating' => 5,
                    'review_text' => 'Amazing product! Perfect! Incredible quality!',
                    'meta_data' => ['verified_purchase' => false]
                ],
                [
                    'id' => 'R002',
                    'rating' => 4,
                    'review_text' => 'Good product, works well after 3 months of use. Some minor issues but overall satisfied.',
                    'meta_data' => ['verified_purchase' => true]
                ]
            ])
        ]);

        Http::fake([
            'localhost:11434/api/tags' => Http::response(['models' => []], 200),
            'localhost:11434/api/generate' => Http::response([
                'model' => 'qwen2.5:7b',
                'response' => '[{"id":"R001","score":85,"label":"fake","confidence":0.9},{"id":"R002","score":35,"label":"genuine","confidence":0.8}]',
                'done' => true,
            ])
        ]);

        $this->artisan('test:new-scoring', ['--asin' => 'B0TESTREAL'])
            ->expectsOutputToContain('Testing new scoring system with 5 reviews from ASIN B0TESTREAL...')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_new_scoring_command_handles_missing_asin()
    {
        $this->artisan('test:new-scoring', ['--asin' => 'B0NONEXISTENT'])
            ->expectsOutput('ASIN B0NONEXISTENT not found or has no reviews')
            ->assertExitCode(1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reanalyze_graded_products_dry_run_mode()
    {
        // Create products with poor grades
        AsinData::factory()->create([
            'asin' => 'B0GRADE001',
            'grade' => 'F',
            'fake_percentage' => 85.0,
            'reviews' => json_encode([['id' => 'R001', 'text' => 'Test review']]),
            'openai_result' => json_encode(['detailed_scores' => ['R001' => 85]])
        ]);

        AsinData::factory()->create([
            'asin' => 'B0GRADE002',
            'grade' => 'D',
            'fake_percentage' => 75.0,
            'reviews' => json_encode([['id' => 'R002', 'text' => 'Another review']]),
            'openai_result' => json_encode(['detailed_scores' => ['R002' => 75]])
        ]);

        $this->artisan('reanalyze:graded-products', ['--dry-run' => true, '--limit' => 10])
            ->expectsOutputToContain('Found 2 products with specified grades.')
            ->expectsOutputToContain('🔍 DRY RUN MODE - No changes will be made')
            ->expectsOutputToContain('Grade F: 1 products')
            ->expectsOutputToContain('Grade D: 1 products')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reanalyze_graded_products_filters_by_grade()
    {
        // Create products with different grades
        AsinData::factory()->create([
            'asin' => 'B0GRADEA',
            'grade' => 'A',
            'fake_percentage' => 15.0,
            'reviews' => json_encode([['id' => 'R001']]),
            'openai_result' => json_encode(['detailed_scores' => ['R001' => 15]])
        ]);

        AsinData::factory()->create([
            'asin' => 'B0GRADEF',
            'grade' => 'F',
            'fake_percentage' => 90.0,
            'reviews' => json_encode([['id' => 'R002']]),
            'openai_result' => json_encode(['detailed_scores' => ['R002' => 90]])
        ]);

        // Test filtering only F grades
        $this->artisan('reanalyze:graded-products', ['--grades' => 'F', '--dry-run' => true])
            ->expectsOutput('Found 1 products with specified grades.')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reanalyze_graded_products_handles_no_matching_products()
    {
        // Create product with good grade
        AsinData::factory()->create([
            'asin' => 'B0GOODGRADE',
            'grade' => 'A',
            'fake_percentage' => 10.0
        ]);

        $this->artisan('reanalyze:graded-products', ['--grades' => 'F', '--dry-run' => true])
            ->expectsOutput('No products found with grades F that have review data.')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reanalyze_graded_products_respects_limit()
    {
        // Create multiple F-grade products
        for ($i = 1; $i <= 5; $i++) {
            AsinData::factory()->create([
                'asin' => "B0LIMIT{$i}",
                'grade' => 'F',
                'fake_percentage' => 80.0 + $i,
                'reviews' => json_encode([['id' => "R{$i}"]]),
                'openai_result' => json_encode(['detailed_scores' => ["R{$i}" => 80 + $i]])
            ]);
        }

        $this->artisan('reanalyze:graded-products', ['--limit' => 3, '--dry-run' => true])
            ->expectsOutputToContain('Found 5 products with specified grades.')
            ->expectsOutputToContain('Will process 3 products (limited by --limit option).')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function analyze_fake_detection_provides_recommendations()
    {
        // Create data with very high fake percentages to trigger warnings
        AsinData::factory()->create([
            'asin' => 'B0HIGHFAKE',
            'fake_percentage' => 85.0,
            'openai_result' => json_encode([
                'detailed_scores' => [
                    'R001' => 95,
                    'R002' => 90,
                    'R003' => 85,
                    'R004' => 80
                ],
                'analysis_provider' => 'Ollama-qwen2.5:7b',
                'total_cost' => 0.0
            ])
        ]);

        $this->artisan('analyze:fake-detection', ['--limit' => 5])
            ->expectsOutputToContain('⚠️  HIGH SCORE RATE:')
            ->expectsOutputToContain('⚠️  Ollama-qwen2.5:7b has very high fake detection rate:')
            ->expectsOutputToContain('→ Consider softening OLLAMA prompt language')
            ->assertExitCode(0);
    }
}
