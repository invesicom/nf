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
  @if($asinData->product_image_url)
  <meta property="og:image" content="{{ $asinData->product_image_url }}" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
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
  @if($asinData->product_image_url)
  <meta property="twitter:image" content="{{ $asinData->product_image_url }}" />
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
  <meta name="msapplication-TileColor" content="#ffffff">
  <meta name="msapplication-TileImage" content="/img/ms-icon-144x144.png">
  <meta name="theme-color" content="#ffffff">

  <!-- TailwindCSS -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- JSON-LD Structured Data -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Product",
    "name": "{{ $asinData->product_title ?? 'Amazon Product' }}",
    "description": "{{ $seo_data['review_summary'] }}",
    @if($asinData->product_image_url)
    "image": "{{ $asinData->product_image_url }}",
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
    "sameAs": "https://www.amazon.com/dp/{{ $asinData->asin }}"
  }
  </script>

  <!-- Additional Review Analysis Schema -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "AnalysisNewsArticle",
    "headline": "{{ $meta_title }}",
    "description": "{{ $meta_description }}",
    "author": {
      "@type": "Organization",
      "name": "Null Fake",
      "url": "{{ url('/') }}"
    },
    "publisher": {
      "@type": "Organization",
      "name": "Null Fake",
      "logo": {
        "@type": "ImageObject",
        "url": "{{ url('/img/nullfake.png') }}"
      }
    },
    "datePublished": "{{ $asinData->updated_at->toISOString() }}",
    "dateModified": "{{ $asinData->updated_at->toISOString() }}",
    "mainEntityOfPage": {
      "@type": "WebPage",
      "@id": "{{ url($canonical_url) }}"
    },
    "about": {
      "@type": "Product",
      "name": "{{ $asinData->product_title ?? 'Amazon Product' }}",
      "identifier": "{{ $asinData->asin }}"
    },
    "mentions": [
      {
        "@type": "Thing",
        "name": "Fake Reviews"
      },
      {
        "@type": "Thing",
        "name": "Review Analysis"
      },
      {
        "@type": "Thing",
        "name": "Amazon Product Reviews"
      }
    ]
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
      "url": "https://www.amazon.com/dp/{{ $asinData->asin }}"
    }
  }
  </script>

  <!-- Analysis Methodology Schema for AI Understanding -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "ResearchProject",
    "name": "Amazon Review Authenticity Analysis",
    "description": "AI-powered analysis to detect fake, manipulated, or inauthentic product reviews",
    "researcher": {
      "@type": "Organization",
      "name": "Null Fake"
    },
    "funding": {
      "@type": "Organization",
      "name": "Independent"
    },
    "studySubject": {
      "@type": "Product",
      "name": "{{ $asinData->product_title ?? 'Amazon Product' }}",
      "identifier": "{{ $asinData->asin }}"
    },
    "measurementTechnique": [
      "Natural Language Processing",
      "Machine Learning Classification", 
      "Pattern Recognition",
      "Statistical Analysis",
      "Review Authenticity Scoring"
    ],
    "result": {
      "@type": "Dataset",
      "name": "Review Authenticity Analysis Results",
      "description": "{{ $asinData->explanation }}",
      "variableMeasured": "fake_review_percentage",
      "value": "{{ $asinData->fake_percentage ?? 0 }}%"
    },
    "dateCreated": "{{ $asinData->updated_at->toISOString() }}",
    "license": "https://creativecommons.org/licenses/by/4.0/"
  }
  </script>
</head>

<body class="bg-gray-50 min-h-screen">
  <header class="bg-white shadow p-4">
    <div class="max-w-4xl mx-auto flex items-center justify-between">
      <div class="flex items-center pr-4 md:pr-0 space-x-3">
        <a href="{{ route('home') }}">
          <img src="/img/nullfake.png" alt="Null Fake Logo" class="h-12 w-auto object-contain" />
        </a>
      </div>
      <p class="text-sm text-gray-500">Amazon Review Analysis</p>
    </div>
  </header>

  <main class="max-w-4xl mx-auto mt-8 p-6">
    <!-- Product Information -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
      <div class="flex flex-col md:flex-row gap-6">
        @if($asinData->product_image_url)
        <div class="flex-shrink-0">
          <img src="{{ $asinData->product_image_url }}" 
               alt="{{ $asinData->product_title ?? 'Product Image' }}" 
               class="w-48 h-48 object-contain rounded-lg border">
        </div>
        @endif
        
        <div class="flex-1">
          <h1 class="text-2xl font-bold text-gray-900 mb-4">
            {{ $asinData->product_title ?? 'Amazon Product Analysis' }}
          </h1>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
              <span class="text-sm text-gray-600">ASIN:</span>
              <span class="font-mono text-sm">{{ $asinData->asin }}</span>
            </div>
            <div>
              <span class="text-sm text-gray-600">Analysis Date:</span>
              <span class="text-sm">{{ $asinData->updated_at->format('M j, Y') }}</span>
            </div>
          </div>
          
          <div class="flex gap-4 mb-4">
            <a href="{{ $amazon_url }}" 
               target="_blank" 
               rel="noopener noreferrer"
               class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
              View on Amazon
            </a>
            <a href="{{ route('home') }}" 
               class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
              Analyze Another Product
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
          <p class="text-gray-700">{{ $asinData->explanation }}</p>
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
              <p class="text-sm text-blue-700">
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
                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Copy Link
        </button>
      </div>
    </div>
  </main>

  <footer class="bg-gray-800 text-white py-8 mt-12">
    <div class="max-w-4xl mx-auto px-6 text-center">
      <p class="text-sm">
        &copy; 2025 Null Fake. AI-powered Amazon review analysis. 
        <a href="{{ route('home') }}" class="text-blue-400 hover:text-blue-300">Analyze more products</a>
      </p>
    </div>
  </footer>

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
      button.classList.remove('bg-blue-500');
      
      setTimeout(() => {
        button.textContent = originalText;
        button.classList.remove('bg-green-500');
        button.classList.add('bg-blue-500');
      }, 2000);
    }
  </script>
</body>
</html> 