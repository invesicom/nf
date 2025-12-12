<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Product Not Found - {{ $asin }} | Null Fake</title>
  <meta name="description" content="The Amazon product {{ $asin }} has not been analyzed yet. Analyze it now on Null Fake." />
  <meta name="robots" content="noindex, nofollow" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  
  <!-- TailwindCSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  @vite(['resources/css/app.css'])

  @include('partials.adsense')
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
        <p class="text-sm text-white">
          <strong>Want to analyze this product?</strong> 
          Copy the Amazon URL and paste it on our home page to get started.
        </p>
      </div>
    </div>
  </main>

  @include('partials.footer')
</body>
</html> 