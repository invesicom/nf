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
  @vite(['resources/css/app.css'])
</head>

<body class="bg-gray-50 min-h-screen">
  @include('partials.header')

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
        <a href="{{ $amazon_url }}" 
           target="_blank" 
           rel="noopener noreferrer"
           class="inline-block bg-orange-500 hover:bg-orange-600 text-white px-6 py-3 rounded-lg font-medium">
          View on Amazon
        </a>
      </div>

      <div class="mt-8 p-4 bg-brand bg-opacity-5 rounded-lg">
        <p class="text-sm text-brand-dark">
          <strong>Want to analyze this product?</strong> 
          Copy the Amazon URL and paste it on our home page to get started.
        </p>
      </div>
    </div>
  </main>

  <footer class="text-center text-gray-500 text-sm mt-10 p-2">
    <div class="flex items-center justify-center mb-2">
      <span class="flex items-center">
        built with
        <span class="inline-block align-middle text-red-500 mx-1" aria-label="love" title="love">
          <svg xmlns="http://www.w3.org/2000/svg" class="inline align-middle h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
            <path d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z"/>
          </svg>
        </span>
        by&nbsp;<a href="https://shift8web.ca" class="text-indigo-600 hover:underline" target="_blank" rel="noopener">shift8 web</a>
      </span>
      <span class="mx-2">•</span>
      <a href="{{ route('products.index') }}" class="text-gray-600 hover:text-gray-800 flex items-center" title="Browse all analyzed products">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
        All analyzed products
      </a>
      <span class="mx-2">•</span>
      <a href="https://github.com/stardothosting/nullfake" class="text-gray-600 hover:text-gray-800 flex items-center" target="_blank" rel="noopener" title="View source on GitHub">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="currentColor" viewBox="0 0 24 24">
          <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.30.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
        </svg>
        GitHub
      </a>
    </div>
    <div class="flex items-center justify-center space-x-4 text-xs">
      <a href="https://github.com/stardothosting/nullfake/blob/main/LICENSE" class="text-gray-500 hover:text-gray-700" target="_blank" rel="noopener">MIT License</a>
      <span>•</span>
      <a href="/privacy" class="text-gray-500 hover:text-gray-700">Privacy Policy</a>
    </div>
  </footer>
</body>
</html> 