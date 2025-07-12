<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $meta_title }}</title>
  <meta name="description" content="{{ $meta_description }}" />
  <meta name="keywords" content="Amazon review analysis, fake review detector, {{ $asinData->asin }}, {{ $asinData->product_title ?? 'Amazon product' }}, review authenticity" />
  <meta name="author" content="Null Fake" />
  
  <!-- SEO and Robots Configuration -->
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
  <meta name="googlebot" content="index, follow" />
  
  <link rel="canonical" href="{{ url()->current() }}" />

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website" />
  <meta property="og:url" content="{{ url()->current() }}" />
  <meta property="og:title" content="{{ $meta_title }}" />
  <meta property="og:description" content="{{ $meta_description }}" />
  @if($asinData->product_image_url)
  <meta property="og:image" content="{{ $asinData->product_image_url }}" />
  @endif

  <!-- Twitter -->
  <meta property="twitter:card" content="summary_large_image" />
  <meta property="twitter:url" content="{{ url()->current() }}" />
  <meta property="twitter:title" content="{{ $meta_title }}" />
  <meta property="twitter:description" content="{{ $meta_description }}" />
  @if($asinData->product_image_url)
  <meta property="twitter:image" content="{{ $asinData->product_image_url }}" />
  @endif

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
    "description": "{{ $meta_description }}",
    @if($asinData->product_image_url)
    "image": "{{ $asinData->product_image_url }}",
    @endif
    "brand": {
      "@type": "Brand",
      "name": "Amazon"
    },
    "aggregateRating": {
      "@type": "AggregateRating",
      "ratingValue": "{{ $asinData->adjusted_rating }}",
      "bestRating": "5",
      "worstRating": "1",
      "ratingCount": "{{ count($asinData->getReviewsArray()) }}"
    },
    "review": {
      "@type": "Review",
      "reviewRating": {
        "@type": "Rating",
        "ratingValue": "{{ $asinData->grade === 'A' ? 5 : ($asinData->grade === 'B' ? 4 : ($asinData->grade === 'C' ? 3 : ($asinData->grade === 'D' ? 2 : 1))) }}",
        "bestRating": "5"
      },
      "author": {
        "@type": "Organization",
        "name": "Null Fake"
      },
      "reviewBody": "{{ $asinData->explanation }}"
    }
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
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div class="text-center p-3 bg-gray-50 rounded-lg">
            <div class="text-lg font-bold text-gray-900">{{ count($asinData->getReviewsArray()) }}</div>
            <div class="text-sm text-gray-600">Total Reviews</div>
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