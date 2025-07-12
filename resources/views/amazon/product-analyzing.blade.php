<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Analysis in Progress - {{ $asinData->asin }} | Null Fake</title>
  <meta name="description" content="Amazon product {{ $asinData->asin }} is currently being analyzed. Check back soon for results." />
  <meta name="robots" content="noindex, nofollow" />
  
  <!-- TailwindCSS -->
  <script src="https://cdn.tailwindcss.com"></script>
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

  <main class="max-w-3xl mx-auto mt-12 p-6">
    <div class="bg-white rounded-lg shadow-md p-8 text-center">
      <div class="mb-6">
        <div class="w-16 h-16 mx-auto bg-blue-100 rounded-full flex items-center justify-center mb-4">
          <svg class="w-8 h-8 text-blue-500 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Analysis in Progress</h1>
        <p class="text-gray-600 mb-4">
          We're currently analyzing this Amazon product. This usually takes a few minutes.
        </p>
        <div class="bg-gray-50 p-3 rounded-lg mb-6">
          <span class="text-sm text-gray-600">ASIN:</span>
          <span class="font-mono text-sm font-medium">{{ $asinData->asin }}</span>
        </div>
      </div>

      <!-- Progress Information -->
      <div class="mb-6">
        <div class="bg-blue-50 p-4 rounded-lg">
          <h3 class="font-semibold text-blue-900 mb-2">Analysis Status</h3>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between">
              <span>Reviews Collected:</span>
              <span class="font-medium">{{ !empty($asinData->getReviewsArray()) ? '✓ Complete' : '⏳ In Progress' }}</span>
            </div>
            <div class="flex justify-between">
              <span>AI Analysis:</span>
              <span class="font-medium">{{ !empty($asinData->openai_result) ? '✓ Complete' : '⏳ In Progress' }}</span>
            </div>
            <div class="flex justify-between">
              <span>Product Data:</span>
              <span class="font-medium">{{ $asinData->have_product_data ? '✓ Complete' : '⏳ In Progress' }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="space-y-4">
        <button onclick="window.location.reload()" 
                class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-medium">
          Refresh Page
        </button>
        
        <div class="text-sm text-gray-500">
          <p>or</p>
        </div>
        
        <a href="{{ $amazon_url }}" 
           target="_blank" 
           rel="noopener noreferrer"
           class="inline-block bg-orange-500 hover:bg-orange-600 text-white px-6 py-3 rounded-lg font-medium">
          View on Amazon
        </a>
      </div>

      <div class="mt-8 p-4 bg-yellow-50 rounded-lg">
        <p class="text-sm text-yellow-700">
          <strong>Please be patient!</strong> 
          Our AI is analyzing {{ count($asinData->getReviewsArray()) }} reviews to detect fake content and provide accurate ratings.
        </p>
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
    // Auto-refresh every 30 seconds
    setTimeout(function() {
      window.location.reload();
    }, 30000);
  </script>
</body>
</html> 