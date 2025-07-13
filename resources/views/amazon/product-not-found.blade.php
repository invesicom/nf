<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Product Not Found - {{ $asin }} | Null Fake</title>
  <meta name="description" content="The Amazon product {{ $asin }} has not been analyzed yet. Analyze it now on Null Fake." />
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
        <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-4">
          <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Product Not Found</h1>
        <p class="text-gray-600 mb-4">
          We haven't analyzed this Amazon product yet.
        </p>
        <div class="bg-gray-50 p-3 rounded-lg mb-6">
          <span class="text-sm text-gray-600">ASIN:</span>
          <span class="font-mono text-sm font-medium">{{ $asin }}</span>
        </div>
      </div>

      <div class="space-y-4">
        <a href="{{ route('home') }}" 
           class="inline-block bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-medium">
          Analyze This Product
        </a>
        
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

      <div class="mt-8 p-4 bg-blue-50 rounded-lg">
        <p class="text-sm text-blue-700">
          <strong>Want to analyze this product?</strong> 
          Copy the Amazon URL and paste it on our home page to get started.
        </p>
      </div>
    </div>
  </main>

  <footer class="bg-gray-800 text-white py-8 mt-12">
    <div class="max-w-4xl mx-auto px-6 text-center">
      <p class="text-sm">
        &copy; 2025 Null Fake. AI-powered Amazon review analysis. 
        <a href="{{ route('home') }}" class="text-blue-400 hover:text-blue-300">Analyze products</a>
      </p>
    </div>
  </footer>
</body>
</html> 