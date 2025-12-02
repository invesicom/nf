<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $meta_title }}</title>
  <meta name="description" content="{{ $meta_description }}" />
  <meta name="keywords" content="{{ $seo_data['keywords'] }}" />
  <meta name="author" content="Null Fake" />
  
  <!-- Enhanced SEO Meta Tags -->
  <meta name="rating" content="{{ $asinData->adjusted_rating ?? 0 }}" />
  <meta name="review-grade" content="{{ $asinData->grade ?? 'N/A' }}" />
  <meta name="fake-review-percentage" content="{{ $asinData->fake_percentage ?? 0 }}" />
  <meta name="trust-score" content="{{ $seo_data['trust_score'] }}" />
  <meta name="review-summary" content="{{ $seo_data['review_summary'] }}" />
  
  <!-- SEO and Robots Configuration -->
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
  <meta name="googlebot" content="index, follow" />
  <meta name="bingbot" content="index, follow" />
  
  <!-- AI/LLM Crawler Directives -->
  <meta name="GPTBot" content="index, follow" />
  <meta name="ChatGPT-User" content="index, follow" />
  <meta name="CCBot" content="index, follow" />
  <meta name="anthropic-ai" content="index, follow" />
  <meta name="Claude-Web" content="index, follow" />
  
  <link rel="canonical" href="{{ url($canonical_url) }}" />
  <link rel="sitemap" type="application/xml" href="{{ url('/sitemap.xml') }}" />

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="product" />
  <meta property="og:url" content="{{ url($canonical_url) }}" />
  <meta property="og:title" content="{{ $seo_data['social_title'] }}" />
  <meta property="og:description" content="{{ $seo_data['social_description'] }}" />
  <meta property="og:site_name" content="Null Fake" />
  @if($asinData->social_image_url)
  <meta property="og:image" content="{{ $asinData->social_image_url }}" />
  <meta property="og:image:type" content="image/jpeg" />
  <meta property="og:image:alt" content="{{ $asinData->product_title ?? 'Product image' }}" />
  @endif
  <!-- Product specific Open Graph -->
  <meta property="product:price:amount" content="N/A" />
  <meta property="product:availability" content="in stock" />
  <meta property="product:condition" content="new" />
  <meta property="product:rating" content="{{ $asinData->adjusted_rating ?? 0 }}" />
  <meta property="product:rating:scale" content="5" />

  <!-- Twitter -->
  <meta property="twitter:card" content="summary_large_image" />
  <meta property="twitter:site" content="@nullfake" />
  <meta property="twitter:url" content="{{ url($canonical_url) }}" />
  <meta property="twitter:title" content="{{ $seo_data['social_title'] }}" />
  <meta property="twitter:description" content="{{ $seo_data['social_description'] }}" />
  @if($asinData->social_image_url)
  <meta property="twitter:image" content="{{ $asinData->social_image_url }}" />
  <meta property="twitter:image:alt" content="{{ $asinData->product_title ?? 'Product image' }}" />
  @endif
  <!-- Twitter Product Card -->
  <meta name="twitter:label1" content="Grade" />
  <meta name="twitter:data1" content="{{ $asinData->grade ?? 'N/A' }}" />
  <meta name="twitter:label2" content="Fake Reviews" />
  <meta name="twitter:data2" content="{{ $asinData->fake_percentage ?? 0 }}%" />

  <!-- Favicon -->
  <link rel="apple-touch-icon" sizes="57x57" href="/img/apple-icon-57x57.png">
  <link rel="apple-touch-icon" sizes="60x60" href="/img/apple-icon-60x60.png">
  <link rel="apple-touch-icon" sizes="72x72" href="/img/apple-icon-72x72.png">
  <link rel="apple-touch-icon" sizes="76x76" href="/img/apple-icon-76x76.png">
  <link rel="apple-touch-icon" sizes="114x114" href="/img/apple-icon-114x114.png">
  <link rel="apple-touch-icon" sizes="120x120" href="/img/apple-icon-120x120.png">
  <link rel="apple-touch-icon" sizes="144x144" href="/img/apple-icon-144x144.png">
  <link rel="apple-touch-icon" sizes="152x152" href="/img/apple-icon-152x152.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/img/apple-icon-180x180.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/img/android-icon-192x192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="96x96" href="/img/favicon-96x96.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon-16x16.png">
  <link rel="manifest" href="/manifest.json">
  <meta name="msapplication-TileColor" content="#424da0">
  <meta name="msapplication-TileImage" content="/img/ms-icon-144x144.png">
  <meta name="theme-color" content="#424da0">

  <!-- TailwindCSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  @vite(['resources/css/app.css', 'resources/js/app.js'])

  <!-- JSON-LD Structured Data -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Product",
    "name": "{{ $asinData->product_title ?? 'Amazon Product' }}",
    "description": "{{ $seo_data['review_summary'] }}",
    @if($asinData->product_image_url)
    "image": "{{ $asinData->social_image_url }}",
    @endif
    "brand": {
      "@type": "Brand",
      "name": "Amazon"
    },
    "sku": "{{ $asinData->asin }}",
    "gtin": "{{ $asinData->asin }}",
    "aggregateRating": {
      "@type": "AggregateRating",
      "ratingValue": "{{ $asinData->adjusted_rating }}",
      "bestRating": "5",
      "worstRating": "1",
      "ratingCount": "{{ count($asinData->getReviewsArray()) }}",
      "reviewCount": "{{ count($asinData->getReviewsArray()) }}"
    },
    "review": [
      {
        "@type": "Review",
        "reviewRating": {
          "@type": "Rating",
          "ratingValue": "{{ $asinData->grade === 'A' ? 5 : ($asinData->grade === 'B' ? 4 : ($asinData->grade === 'C' ? 3 : ($asinData->grade === 'D' ? 2 : 1))) }}",
          "bestRating": "5",
          "worstRating": "1"
        },
        "author": {
          "@type": "Organization",
          "name": "Null Fake - AI Review Analysis"
        },
        "reviewBody": "{{ $asinData->explanation }}",
        "datePublished": "{{ $asinData->updated_at->toISOString() }}",
        "headline": "Fake Review Analysis - Grade {{ $asinData->grade ?? 'N/A' }}"
      }
    ],
    "additionalProperty": [
      {
        "@type": "PropertyValue",
        "name": "Fake Review Percentage",
        "value": "{{ $asinData->fake_percentage ?? 0 }}%"
      },
      {
        "@type": "PropertyValue",
        "name": "Authenticity Grade",
        "value": "{{ $asinData->grade ?? 'N/A' }}"
      },
      {
        "@type": "PropertyValue",
        "name": "Trust Score",
        "value": "{{ $seo_data['trust_score'] }}/100"
      },
      {
        "@type": "PropertyValue",
        "name": "Amazon Original Rating",
        "value": "{{ $asinData->amazon_rating ?? 0 }}"
      },
      {
        "@type": "PropertyValue",
        "name": "Adjusted Rating",
        "value": "{{ $asinData->adjusted_rating ?? 0 }}"
      }
    ],
    "url": "{{ url($canonical_url) }}",
    "sameAs": "{{ $amazon_url }}"
  }
  </script>

  <!-- AI/LLM Specific Structured Data -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Dataset",
    "name": "Amazon Product Review Analysis Data",
    "description": "Comprehensive fake review analysis dataset for {{ $asinData->product_title ?? 'Amazon Product' }}",
    "creator": {
      "@type": "Organization",
      "name": "Null Fake",
      "description": "AI-powered Amazon review authenticity analysis platform"
    },
    "distribution": {
      "@type": "DataDownload",
      "contentUrl": "{{ url($canonical_url) }}",
      "encodingFormat": "text/html"
    },
    "temporalCoverage": "{{ $asinData->updated_at->toISOString() }}",
    "spatialCoverage": "{{ $asinData->country ?? 'US' }}",
    "variableMeasured": [
      {
        "@type": "PropertyValue",
        "name": "fake_review_percentage",
        "value": "{{ $asinData->fake_percentage ?? 0 }}",
        "unitText": "percent",
        "description": "Percentage of reviews identified as potentially fake or inauthentic"
      },
      {
        "@type": "PropertyValue", 
        "name": "authenticity_grade",
        "value": "{{ $asinData->grade ?? 'N/A' }}",
        "description": "Letter grade (A-F) representing overall review authenticity"
      },
      {
        "@type": "PropertyValue",
        "name": "adjusted_rating",
        "value": "{{ $asinData->adjusted_rating ?? 0 }}",
        "unitText": "stars",
        "description": "Product rating adjusted for fake review removal"
      },
      {
        "@type": "PropertyValue",
        "name": "reviews_analyzed", 
        "value": "{{ count($asinData->getReviewsArray()) }}",
        "unitText": "reviews",
        "description": "Total number of reviews analyzed for authenticity"
      }
      @if($asinData->total_reviews_on_amazon)
      ,{
        "@type": "PropertyValue",
        "name": "total_reviews_on_amazon",
        "value": "{{ $asinData->total_reviews_on_amazon }}",
        "unitText": "reviews", 
        "description": "Total number of reviews visible on Amazon product page"
      }
      @endif
    ],
    "license": "https://creativecommons.org/licenses/by/4.0/",
    "isBasedOn": {
      "@type": "Product",
      "name": "{{ $asinData->product_title ?? 'Amazon Product' }}",
      "identifier": "{{ $asinData->asin }}",
      "url": "{{ $amazon_url }}"
    }
  }
  </script>

  <!-- Analysis Methodology Schema for AI Understanding -->
  <script type="application/ld+json">
  {!! json_encode($seo_data['analysis_schema'] ?? [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
  </script>

  <!-- FAQ Schema for AI Question Answering -->
  <script type="application/ld+json">
  {!! json_encode($seo_data['faq_schema'] ?? [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
  </script>

  <!-- HowTo Schema for Process Understanding -->
  <script type="application/ld+json">
  {!! json_encode($seo_data['how_to_schema'] ?? [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
  </script>

  <!-- AI-Optimized Metadata for Enhanced Understanding -->
  <meta name="ai:summary" content="{{ $seo_data['ai_summary'] ?? '' }}" />
  <meta name="ai:confidence" content="{{ $seo_data['confidence_score'] ?? 0 }}" />
  <meta name="ai:methodology" content="machine_learning,natural_language_processing,statistical_analysis" />
  <meta name="ai:data_freshness" content="{{ $seo_data['data_freshness'] ?? '' }}" />
  
  <!-- Question-Answer Structured Data for AI -->
  @if(isset($seo_data['question_answers']))
  @foreach($seo_data['question_answers'] as $qa)
  <meta name="ai:qa:question" content="{{ $qa['question'] }}" />
  <meta name="ai:qa:answer" content="{{ $qa['answer'] }}" />
  @endforeach
  @endif
</head>

<body class="bg-gray-50 min-h-screen">
  @include('partials.header')

  <main class="max-w-4xl mx-auto mt-8 p-6">
    <!-- Product Information -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
      <div class="flex flex-col md:flex-row gap-6">
        @if($asinData->product_image_url)
        <div class="flex-shrink-0">
          <img src="{{ $asinData->social_image_url }}" 
               alt="{{ $asinData->product_title ?? 'Product Image' }}" 
               class="w-48 h-48 object-contain rounded-lg border">
        </div>
        @endif
        
        <div class="flex-1">
          <h1 class="text-2xl font-bold text-gray-900 mb-4">
            {{ $asinData->product_title ?? 'Amazon Product Analysis' }}
          </h1>
          
          @if($asinData->product_description && !empty(trim($asinData->product_description)))
          <div class="mb-4">
            <p class="text-gray-700 leading-relaxed">
              {{ $asinData->product_description }}
            </p>
          </div>
          @endif
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
              <span class="text-sm text-gray-600">ASIN:</span>
              <span class="font-mono text-sm">{{ $asinData->asin }}</span>
            </div>
            <div>
              <span class="text-sm text-gray-600">Analysis Date:</span>
              <span class="text-sm">
                {{ ($asinData->first_analyzed_at ?? $asinData->updated_at)->format('M j, Y') }}
                @if($asinData->last_analyzed_at && $asinData->first_analyzed_at && $asinData->last_analyzed_at->ne($asinData->first_analyzed_at))
                  <span class="text-xs text-gray-500">(re-analyzed {{ $asinData->last_analyzed_at->format('M j, Y') }})</span>
                @endif
              </span>
            </div>
          </div>
          
          <div class="flex flex-wrap gap-4 mb-4">
            <a href="{{ $amazon_url }}" 
               target="_blank" 
               rel="noopener noreferrer"
               class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
              View on Amazon
            </a>
            <a href="{{ route('home') }}" 
               class="bg-brand hover:bg-brand-dark text-white px-4 py-2 rounded-lg text-sm font-medium">
              Analyze Another Product
            </a>
            <a href="#price-analysis" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center">
              <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"></path>
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"></path>
              </svg>
              View Price Analysis
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- Analysis Results -->
    <div class="bg-white rounded-lg shadow-md p-6">
      <h2 class="text-xl font-bold text-gray-900 mb-6">Review Analysis Results</h2>
      
      <!-- Grade and Summary -->
      <div class="flex flex-col md:flex-row gap-6 mb-6">
        <div class="flex-1">
          <div class="text-center p-6 rounded-lg {{ $asinData->grade === 'A' ? 'bg-green-100' : ($asinData->grade === 'B' ? 'bg-yellow-100' : ($asinData->grade === 'C' ? 'bg-orange-100' : ($asinData->grade === 'D' ? 'bg-red-100' : 'bg-red-200'))) }}">
            <div class="text-4xl font-bold {{ $asinData->grade === 'A' ? 'text-green-600' : ($asinData->grade === 'B' ? 'text-yellow-600' : ($asinData->grade === 'C' ? 'text-orange-600' : ($asinData->grade === 'D' ? 'text-red-600' : 'text-red-800'))) }} mb-2">
              {{ $asinData->grade ?? 'N/A' }}
            </div>
            <div class="text-sm text-gray-600">Authenticity Grade</div>
          </div>
        </div>
        
        <div class="flex-2">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="text-center p-4 bg-gray-50 rounded-lg">
              <div class="text-2xl font-bold text-gray-900">{{ $asinData->fake_percentage ?? 0 }}%</div>
              <div class="text-sm text-gray-600">Fake Reviews</div>
            </div>
            <div class="text-center p-4 bg-gray-50 rounded-lg">
              <div class="text-2xl font-bold text-gray-900">{{ $asinData->amazon_rating ?? 0 }}</div>
              <div class="text-sm text-gray-600">Original Rating</div>
            </div>
            <div class="text-center p-4 bg-gray-50 rounded-lg">
              <div class="text-2xl font-bold text-green-600">{{ $asinData->adjusted_rating ?? 0 }}</div>
              <div class="text-sm text-gray-600">Adjusted Rating</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Analysis Explanation -->
      <div class="mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-3">Analysis Summary</h3>
        <div class="bg-gray-50 p-4 rounded-lg">
          <div class="text-gray-700 space-y-4">
            @if($asinData->explanation)
              @foreach(explode("\n\n", $asinData->explanation) as $paragraph)
                @if(trim($paragraph))
                  <p>{{ trim($paragraph) }}</p>
                @endif
              @endforeach
            @endif
          </div>
        </div>
      </div>

      <!-- Review Statistics -->
      <div class="mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-3">Review Statistics</h3>
        
        <!-- Explanation about review data collection -->
        <div class="bg-blue-50 p-4 rounded-lg mb-4">
          <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-500 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div>
              <h4 class="font-semibold text-blue-800 mb-1">About Review Data Collection</h4>
              <p class="text-base text-blue-700">
                We extract as much review data as Amazon makes available at the time of analysis. 
                The amount may vary due to Amazon's rate limiting, regional restrictions, or other factors. 
                Our analysis is based on the reviews we successfully collected.
              </p>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
          @if($asinData->total_reviews_on_amazon)
          <div class="text-center p-3 bg-gray-50 rounded-lg">
            <div class="text-lg font-bold text-gray-900">{{ number_format($asinData->total_reviews_on_amazon) }}</div>
            <div class="text-sm text-gray-600">Total Reviews on Amazon</div>
          </div>
          @endif
          <div class="text-center p-3 bg-indigo-50 rounded-lg">
            <div class="text-lg font-bold text-indigo-600">{{ count($asinData->getReviewsArray()) }}</div>
            <div class="text-sm text-gray-600">Reviews Analyzed</div>
          </div>
          <div class="text-center p-3 bg-green-50 rounded-lg">
            <div class="text-lg font-bold text-green-600">{{ count($asinData->getReviewsArray()) - round(($asinData->fake_percentage / 100) * count($asinData->getReviewsArray())) }}</div>
            <div class="text-sm text-gray-600">Genuine Reviews</div>
          </div>
          <div class="text-center p-3 bg-red-50 rounded-lg">
            <div class="text-lg font-bold text-red-600">{{ round(($asinData->fake_percentage / 100) * count($asinData->getReviewsArray())) }}</div>
            <div class="text-sm text-gray-600">Fake Reviews</div>
          </div>
          <div class="text-center p-3 bg-blue-50 rounded-lg">
            <div class="text-lg font-bold text-blue-600">{{ number_format(($asinData->adjusted_rating - $asinData->amazon_rating), 2, '.', '') }}</div>
            <div class="text-sm text-gray-600">Rating Difference</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Fake Review Examples Section -->
    @if($asinData->fake_review_examples && count($asinData->fake_review_examples) > 0)
    <div class="bg-white rounded-lg shadow-md p-6 mt-6">
      <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
        <svg class="w-5 h-5 text-red-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
        </svg>
        Why These Reviews Were Flagged as Fake
      </h2>
      
      <div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-lg">
        <p class="text-amber-800 text-base">
          <strong>Transparency Notice:</strong> Our AI analysis identified the following reviews as potentially fake. 
          Each example shows specific reasons why the review raised suspicion, helping you understand our analysis methodology.
        </p>
      </div>

      <div class="space-y-6">
        @foreach($asinData->fake_review_examples as $index => $example)
        <div class="border border-red-200 rounded-lg p-4 bg-red-50">
          <div class="flex justify-between items-start mb-3">
            <div class="flex items-center space-x-2">
              <span class="text-sm font-medium text-red-800">Example {{ $index + 1 }}</span>
              <span class="px-2 py-1 bg-red-600 text-white text-xs rounded-full">
                {{ number_format($example['score'], 0) }}% Fake Risk
              </span>
              @if($example['confidence'] === 'high')
                <span class="px-2 py-1 bg-red-700 text-white text-xs rounded-full">High Confidence</span>
              @elseif($example['confidence'] === 'medium')
                <span class="px-2 py-1 bg-yellow-600 text-white text-xs rounded-full">Medium Confidence</span>
              @else
                <span class="px-2 py-1 bg-gray-500 text-white text-xs rounded-full">Low Confidence</span>
              @endif
            </div>
            <div class="text-xs text-gray-500">
              {{ $example['rating'] }}/5 stars
              @if($example['verified_purchase'])
                • <span class="text-green-600">Verified Purchase</span>
              @else
                • <span class="text-red-600">Unverified</span>
              @endif
            </div>
          </div>

          <!-- Review Text -->
          <div class="mb-3 p-3 bg-white border border-red-300 rounded">
            <p class="text-gray-700 text-base italic">{{ $example['review_text'] }}</p>
          </div>

          <!-- AI Explanation -->
          <div class="mb-3">
            <h4 class="font-semibold text-red-800 mb-2">Why This Review Was Flagged:</h4>
            <p class="text-base text-red-700">{{ $example['explanation'] }}</p>
          </div>

          <!-- Red Flags -->
          @if(!empty($example['red_flags']))
          <div class="mb-3">
            <h4 class="font-semibold text-red-800 mb-2">Specific Red Flags Detected:</h4>
            <div class="flex flex-wrap gap-2">
              @foreach($example['red_flags'] as $flag)
                <span class="px-2 py-1 bg-red-100 text-red-800 text-xs rounded border border-red-300">{{ $flag }}</span>
              @endforeach
            </div>
          </div>
          @endif

          <!-- Analysis Details -->
          <div class="text-xs text-gray-500 border-t border-red-200 pt-2">
            Analyzed by: {{ ucfirst($example['provider']) }} ({{ $example['model'] }})
          </div>
        </div>
        @endforeach
      </div>

      <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
        <p class="text-blue-800 text-base">
          <strong>Note:</strong> These examples represent reviews with the highest fake probability scores (70%+). 
          The AI analysis considers factors like language patterns, specificity, verification status, and suspicious indicators.
          While highly accurate, no automated system is perfect - these should be considered strong indicators rather than definitive proof.
        </p>
      </div>
    </div>
    @endif

    <!-- Price Analysis Section -->
    <div id="price-analysis" class="bg-white rounded-lg shadow-md p-6 mt-6 scroll-mt-4">
      <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
        <svg class="w-5 h-5 text-green-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
          <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"></path>
          <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"></path>
        </svg>
        Price Analysis
      </h2>

      @if($asinData->hasPriceAnalysis())
        @php $priceData = $asinData->price_analysis; @endphp
        
        <!-- Price Summary -->
        <div class="bg-gradient-to-r from-green-50 to-blue-50 p-4 rounded-lg mb-6">
          <p class="text-gray-700">{{ $priceData['summary'] ?? 'Price analysis completed.' }}</p>
        </div>

        <!-- Price Analysis Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
          <!-- MSRP Analysis -->
          <div class="bg-gray-50 p-4 rounded-lg">
            <h4 class="font-semibold text-gray-900 mb-2">MSRP Assessment</h4>
            @if(isset($priceData['msrp_analysis']))
              <div class="space-y-2 text-sm">
                <div>
                  <span class="text-gray-600">Estimated MSRP:</span>
                  <span class="font-medium text-gray-900 block">{{ $priceData['msrp_analysis']['estimated_msrp'] ?? 'N/A' }}</span>
                </div>
                <div>
                  <span class="text-gray-600">Source:</span>
                  <span class="text-gray-700 block">{{ $priceData['msrp_analysis']['msrp_source'] ?? 'N/A' }}</span>
                </div>
                <div>
                  @php
                    $assessment = $priceData['msrp_analysis']['amazon_price_assessment'] ?? 'Unknown';
                    $assessmentColor = match($assessment) {
                      'Below MSRP' => 'text-green-600',
                      'Above MSRP' => 'text-red-600',
                      'At MSRP' => 'text-blue-600',
                      default => 'text-gray-600'
                    };
                  @endphp
                  <span class="text-gray-600">Amazon Price:</span>
                  <span class="font-medium {{ $assessmentColor }} block">{{ $assessment }}</span>
                </div>
              </div>
            @else
              <p class="text-sm text-gray-500">MSRP data unavailable</p>
            @endif
          </div>

          <!-- Market Comparison -->
          <div class="bg-gray-50 p-4 rounded-lg">
            <h4 class="font-semibold text-gray-900 mb-2">Market Position</h4>
            @if(isset($priceData['market_comparison']))
              <div class="space-y-2 text-sm">
                <div>
                  @php
                    $positioning = $priceData['market_comparison']['price_positioning'] ?? 'Unknown';
                    $positionBadge = match($positioning) {
                      'Budget' => 'bg-green-100 text-green-800',
                      'Mid-range' => 'bg-blue-100 text-blue-800',
                      'Premium' => 'bg-purple-100 text-purple-800',
                      'Luxury' => 'bg-amber-100 text-amber-800',
                      default => 'bg-gray-100 text-gray-800'
                    };
                  @endphp
                  <span class="text-gray-600">Positioning:</span>
                  <span class="inline-block px-2 py-1 rounded text-xs font-medium {{ $positionBadge }} mt-1">{{ $positioning }}</span>
                </div>
                <div>
                  <span class="text-gray-600">Alternatives Range:</span>
                  <span class="text-gray-700 block">{{ $priceData['market_comparison']['typical_alternatives_range'] ?? 'N/A' }}</span>
                </div>
                <div>
                  <span class="text-gray-600">Value:</span>
                  <span class="text-gray-700 block">{{ $priceData['market_comparison']['value_proposition'] ?? 'N/A' }}</span>
                </div>
              </div>
            @else
              <p class="text-sm text-gray-500">Market data unavailable</p>
            @endif
          </div>

          <!-- Price Insights -->
          <div class="bg-gray-50 p-4 rounded-lg">
            <h4 class="font-semibold text-gray-900 mb-2">Buying Tips</h4>
            @if(isset($priceData['price_insights']))
              <div class="space-y-2 text-sm">
                @if(!empty($priceData['price_insights']['seasonal_consideration']) && $priceData['price_insights']['seasonal_consideration'] !== 'N/A')
                <div>
                  <span class="text-gray-600">Best Time to Buy:</span>
                  <span class="text-gray-700 block">{{ $priceData['price_insights']['seasonal_consideration'] }}</span>
                </div>
                @endif
                @if(!empty($priceData['price_insights']['deal_indicators']) && $priceData['price_insights']['deal_indicators'] !== 'N/A')
                <div>
                  <span class="text-gray-600">Deal Indicators:</span>
                  <span class="text-gray-700 block">{{ $priceData['price_insights']['deal_indicators'] }}</span>
                </div>
                @endif
                @if(!empty($priceData['price_insights']['caution_flags']) && $priceData['price_insights']['caution_flags'] !== 'N/A')
                <div>
                  <span class="text-gray-600">Watch For:</span>
                  <span class="text-amber-700 block">{{ $priceData['price_insights']['caution_flags'] }}</span>
                </div>
                @endif
              </div>
            @else
              <p class="text-sm text-gray-500">Insights unavailable</p>
            @endif
          </div>
        </div>

        <!-- Disclaimer -->
        <div class="text-xs text-gray-500 border-t pt-3">
          Price analysis generated by AI based on product category and market research. Actual prices may vary.
          Last analyzed: {{ $asinData->price_analyzed_at?->format('M j, Y') ?? 'N/A' }}
        </div>

      @elseif($asinData->isPriceAnalysisProcessing())
        <!-- Processing State -->
        <div class="bg-blue-50 p-6 rounded-lg text-center">
          <div class="animate-pulse flex flex-col items-center">
            <svg class="w-8 h-8 text-blue-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-blue-700 font-medium">Price analysis in progress</p>
            <p class="text-blue-600 text-sm mt-1">This section will update automatically when complete.</p>
          </div>
        </div>

      @else
        <!-- Pending/Not Available State -->
        <div class="bg-gray-50 p-6 rounded-lg text-center">
          <svg class="w-8 h-8 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <p class="text-gray-600 font-medium">Price analysis pending</p>
          <p class="text-gray-500 text-sm mt-1">Price insights will be available shortly.</p>
        </div>
      @endif
    </div>

    <!-- Share Section -->
    <div class="bg-white rounded-lg shadow-md p-6 mt-6">
      <h3 class="text-lg font-semibold text-gray-900 mb-3">Share This Analysis</h3>
      <div class="flex flex-col md:flex-row gap-4">
        <input type="text" 
               value="{{ url()->current() }}" 
               readonly 
               class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-sm"
               id="share-url">
        <button onclick="copyToClipboard()" 
                class="bg-brand hover:bg-brand-dark text-white px-4 py-2 rounded-lg text-sm font-medium">
          Copy Link
        </button>
      </div>
    </div>
  </main>

  <!-- Back to analyzer link -->
  <div class="max-w-4xl mx-auto px-6 mt-8 mb-4">
    <a href="{{ route('home') }}" class="text-indigo-600 hover:text-indigo-800 flex items-center text-sm font-medium">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Analyze new product
    </a>
  </div>

  @include('partials.footer')

  <script>
    function copyToClipboard() {
      const input = document.getElementById('share-url');
      input.select();
      input.setSelectionRange(0, 99999); // For mobile devices
      document.execCommand('copy');
      
      // Show feedback
      const button = event.target;
      const originalText = button.textContent;
      button.textContent = 'Copied!';
      button.classList.add('bg-green-500');
      button.classList.remove('bg-brand');
      
      setTimeout(() => {
        button.textContent = originalText;
        button.classList.remove('bg-green-500');
        button.classList.add('bg-brand');
      }, 2000);
    }
  </script>
</body>
</html> 