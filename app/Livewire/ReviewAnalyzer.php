<?php

namespace App\Livewire;

use App\Services\CaptchaService;
use App\Services\LoggingService;
use App\Services\ReviewAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Livewire component for Amazon product review analysis interface.
 */
class ReviewAnalyzer extends Component
{
    // Form inputs
    public $productUrl = '';
    public $g_recaptcha_response = '';
    public $h_captcha_response = '';
    public $hcaptchaKey;
    public $captcha_passed = false;

    // UI state
    public $loading = false;
    public $progressCurrent = 0;
    public $progressTotal = 0;
    public $error = '';

    // Analysis results - only for display
    public $result = null;
    public $amazon_rating = null;
    public $fake_percentage = null;
    public $faker_rating = null;
    public $grade = null;
    public $grade_color = null;
    public $grade_bg = null;
    public $grade_border = null;
    public $grade_summary = null;
    public $explanation = null;
    public $asinReview = null;

    // Progress tracking properties
    public $progressStep = 0;
    public $progressSteps = [
        1 => 'Validating product URL...',
        2 => 'Authenticating request...',
        3 => 'Checking product database...',
        4 => 'Gathering review information...',
        5 => 'Analyzing reviews with AI...',
        6 => 'Calculating final metrics...',
        7 => 'Finalizing results...',
    ];
    public $progressPercentage = 0;
    public $totalReviewsFound = 0;
    public $currentlyProcessing = '';
    public $progress = 0;
    public $isAnalyzed = false;
    public $gradeColor = '';
    public $gradeBgColor = '';

    public $analysisResult = null;
    public float $adjusted_rating = 0.00;

    protected $rules = [
        'productUrl' => 'required|url',
    ];

    public function mount()
    {
        $this->hcaptchaKey = uniqid();

        // Initialize progress variables to ensure they're available in the template
        $this->progressStep = 0;
        $this->progressPercentage = 0;
        $this->currentlyProcessing = '';
        $this->loading = false;
        $this->totalReviewsFound = 0;
    }

    public function analyze()
    {
        LoggingService::log('=== LIVEWIRE ANALYZE METHOD STARTED (ASYNC MODE) ===');
        
        // For backward compatibility, detect if we should use async mode
        $useAsyncMode = config('analysis.async_enabled', true);
        
        if ($useAsyncMode) {
            $this->analyzeAsync();
        } else {
            $this->analyzeSynchronous();
        }
    }

    /**
     * New async analysis method
     */
    private function analyzeAsync()
    {
        try {
            // Validate input first
            $this->validate([
                'productUrl' => 'required|url',
            ]);

            // Trigger JavaScript-based async analysis
            $this->dispatch('startAsyncAnalysis', [
                'productUrl' => $this->productUrl,
                'captchaData' => [
                    'g_recaptcha_response' => $this->g_recaptcha_response,
                    'h_captcha_response' => $this->h_captcha_response,
                ]
            ]);

        } catch (ValidationException $e) {
            LoggingService::log('Validation error in async analyze method: ' . $e->getMessage());
            $errors = $e->errors();
            $this->error = !empty($errors) ? reset($errors)[0] : $e->getMessage();
            $this->resetAnalysisState();
        }
    }

    /**
     * Original synchronous analysis method (fallback)
     */
    private function analyzeSynchronous()
    {
        LoggingService::log('Using synchronous analysis mode');
        
        try {
            // Original sync logic remains unchanged for compatibility
            $this->result = null;
            $this->error = null;
            $this->amazon_rating = null;
            $this->faker_rating = null;
            $this->fake_percentage = null;
            $this->grade = null;
            $this->grade_color = null;
            $this->grade_bg = null;
            $this->grade_border = null;
            $this->grade_summary = null;
            $this->explanation = null;
            $this->asinReview = null;
            $this->adjusted_rating = 0.00;
            $this->isAnalyzed = false;

            if (empty($this->productUrl)) {
                $this->productUrl = request()->input('productUrl', $this->productUrl);
            }
            
            $this->validate([
                'productUrl' => 'required|url',
            ]);

            // Captcha validation (if not local)
            if (!app()->environment(['local', 'testing'])) {
                if (!$this->captcha_passed) {
                    $captchaService = app(CaptchaService::class);
                    $provider = $captchaService->getProvider();

                    if ($provider === 'recaptcha' && !empty($this->g_recaptcha_response)) {
                        if (!$captchaService->verify($this->g_recaptcha_response)) {
                            throw new \Exception('Captcha verification failed. Please try again.');
                        }
                        $this->captcha_passed = true;
                    } elseif ($provider === 'hcaptcha' && !empty($this->h_captcha_response)) {
                        if (!$captchaService->verify($this->h_captcha_response)) {
                            throw new \Exception('Captcha verification failed. Please try again.');
                        }
                        $this->captcha_passed = true;
                    } else {
                        throw new \Exception('Captcha verification failed. Please try again.');
                    }
                }
            }

            $analysisService = app(ReviewAnalysisService::class);
            $productInfo = $analysisService->checkProductExists($this->productUrl);
            $asinData = $productInfo['asin_data'];

            if ($productInfo['needs_fetching']) {
                $asinData = $analysisService->fetchReviews(
                    $productInfo['asin'],
                    $productInfo['country'],
                    $productInfo['product_url']
                );
            }

            if ($productInfo['needs_openai']) {
                $asinData = $analysisService->analyzeWithOpenAI($asinData);
            }

            $analysisResult = $analysisService->calculateFinalMetrics($asinData);
            $this->setResults($analysisResult);

            if ($asinData->have_product_data) {
                if ($asinData->slug) {
                    return $this->redirect(route('amazon.product.show.slug', [
                        'asin' => $asinData->asin,
                        'slug' => $asinData->slug
                    ]));
                } else {
                    return $this->redirect(route('amazon.product.show', ['asin' => $asinData->asin]));
                }
            }

            $this->isAnalyzed = true;

        } catch (ValidationException $e) {
            LoggingService::log('Validation error in sync analyze method: ' . $e->getMessage());
            $errors = $e->errors();
            $this->error = !empty($errors) ? reset($errors)[0] : $e->getMessage();
            $this->resetAnalysisState();
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Captcha') || str_contains($e->getMessage(), 'captcha')) {
                $this->error = $e->getMessage();
                $this->resetAnalysisState();
                return;
            }
            LoggingService::log('Exception in sync analyze method: '.$e->getMessage());
            $this->error = LoggingService::handleException($e);
            $this->resetAnalysisState();
        }
    }

    public function resetAnalysisState()
    {
        // Reset progress state
        $this->loading = false;
        $this->progressStep = 0;
        $this->progressPercentage = 0;
        $this->totalReviewsFound = 0;
        $this->currentlyProcessing = '';
        $this->progress = 0;
        $this->isAnalyzed = false;
    }

    private function setResults($analysisResult)
    {
        LoggingService::log('Setting results with data: '.json_encode($analysisResult));

        $this->result = $analysisResult;
        $this->fake_percentage = $analysisResult['fake_percentage'] ?? 0;
        $this->amazon_rating = $analysisResult['amazon_rating'] ?? 0;
        $this->adjusted_rating = (float) $analysisResult['adjusted_rating'];
        $this->grade = $analysisResult['grade'] ?? 'N/A';
        $this->explanation = $analysisResult['explanation'] ?? null;

        LoggingService::log('Results set - amazon_rating: '.$this->amazon_rating.', fake_percentage: '.$this->fake_percentage.', adjusted_rating: '.$this->adjusted_rating);
        LoggingService::log('Adjusted rating type: '.gettype($this->adjusted_rating).', value: '.var_export($this->adjusted_rating, true));
    }

    public function render()
    {
        return view('livewire.review-analyzer');
    }

    public function getGradeColor()
    {
        return match ($this->grade) {
            'A'     => 'text-green-600',
            'B'     => 'text-yellow-600',
            'C'     => 'text-orange-600',
            'D'     => 'text-red-600',
            'F'     => 'text-red-800',
            default => 'text-gray-600'
        };
    }

    public function getGradeBgColor()
    {
        return match ($this->grade) {
            'A'     => 'bg-green-100',
            'B'     => 'bg-yellow-100',
            'C'     => 'bg-orange-100',
            'D'     => 'bg-red-100',
            'F'     => 'bg-red-200',
            default => 'bg-gray-100'
        };
    }

    public function clearPreviousResults()
    {
        // Clear all previous results immediately when button is clicked
        // This runs before the analyze() method
        $this->result = null;
        $this->error = null;
        $this->amazon_rating = null;
        $this->faker_rating = null;
        $this->fake_percentage = null;
        $this->grade = null;
        $this->grade_color = null;
        $this->grade_bg = null;
        $this->grade_border = null;
        $this->grade_summary = null;
        $this->explanation = null;
        $this->asinReview = null;
        $this->adjusted_rating = 0.00;
        $this->isAnalyzed = false;

        // Also reset progress state for clean start
        $this->loading = false;
        $this->progressStep = 0;
        $this->progressPercentage = 0;
        $this->currentlyProcessing = '';
        $this->progress = 0;

        // Force Livewire to re-render immediately
        $this->dispatch('resultsCleared');
    }

    public function initializeProgress()
    {
        // Initialize progress state immediately when button is clicked
        // This runs before the analyze() method
        $this->loading = true;
        $this->progressStep = 0;
        $this->progressPercentage = 0;
        $this->totalReviewsFound = 0;
        $this->currentlyProcessing = 'Starting analysis...';
        $this->progress = 0;
        $this->isAnalyzed = false;

        LoggingService::log('=== INITIALIZE PROGRESS CALLED ===');
        LoggingService::log('Progress initialized - loading: true, step: 0');
    }

    public function startAnalysis()
    {
        LoggingService::log('=== START ANALYSIS CALLED ===');

        // Clear previous results
        $this->clearPreviousResults();

        // Force sync of input values (in case wire:model.live has timing issues)
        $this->dispatch('syncInputs');

        // Run the analysis (JavaScript will handle progress simulation)
        $this->analyze();
    }

    // Method to sync the URL from JavaScript if needed
    public function setProductUrl($url)
    {
        $this->productUrl = $url;
        LoggingService::log('Product URL set via JavaScript: ' . $url);
    }

    /**
     * Handle async analysis results
     */
    public function setAsyncResults($result)
    {
        LoggingService::log('Setting async analysis results', [
            'has_result' => !empty($result),
            'has_asin_data' => !empty($result['asin_data']),
        ]);

        if (empty($result) || empty($result['analysis_result'])) {
            $this->error = 'Invalid analysis result received';
            return;
        }

        // Set results from async analysis
        $this->setResults($result['analysis_result']);
        $this->isAnalyzed = true;
        $this->loading = false;

        LoggingService::log('Async analysis results set successfully');
    }
}
