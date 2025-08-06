<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>All Analyzed Products - Null Fake</title>
  <meta name="description" content="Browse all Amazon products analyzed by Null Fake - Amazon Review Analysis Tool. Find authentic reviews and avoid fake products." />
  <meta name="keywords" content="amazon reviews, fake reviews, product analysis, review authenticity, amazon products" />
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 text-gray-800">

<div class="min-h-screen bg-gray-100">
  <!-- Header -->
  <header class="bg-white shadow p-4">
    <div class="max-w-4xl mx-auto flex items-center justify-between">
      <div class="flex items-center pr-4 md:pr-0 space-x-3">
        <a href="/">
          <img src="/img/nullfake.png" alt="Null Fake Logo" class="h-12 w-auto object-contain" />
        </a>
      </div>
      <p class="text-sm text-gray-500">Analyze Amazon reviews for authenticity</p>
    </div>
  </header>

  <!-- Main Content -->
  <main class="max-w-7xl mx-auto px-6 py-8">
    <div class="bg-white rounded-lg shadow-sm p-8">
      <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-4">All Analyzed Products</h1>
        <p class="text-gray-600">Browse {{ number_format($products->total()) }} Amazon products analyzed for review authenticity</p>
      </div>

      @if($products->count() > 0)
        <!-- Products Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-8">
          @foreach($products as $product)
            <div class="bg-gray-50 rounded-lg p-4 hover:shadow-md transition-shadow">
              <a href="{{ route('amazon.product.show.slug', ['asin' => $product->asin, 'slug' => $product->slug ?? 'product']) }}" class="block">
                <!-- Product Image -->
                @if($product->product_image_url)
                  <div class="aspect-square mb-3 bg-white rounded overflow-hidden">
                    <img 
                      src="{{ $product->product_image_url }}" 
                      alt="{{ $product->product_title }}"
                      class="w-full h-full object-contain"
                      loading="lazy"
                    />
                  </div>
                @else
                  <div class="aspect-square mb-3 bg-gray-200 rounded flex items-center justify-center">
                    <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                  </div>
                @endif

                <!-- Product Title -->
                <h3 class="text-sm font-medium text-gray-900 mb-2 line-clamp-2 leading-tight">
                  {{ Str::limit($product->product_title, 80) }}
                </h3>

                <!-- Grade and Stats -->
                <div class="flex items-center justify-between">
                  <div class="flex items-center space-x-2">
                    <!-- Authenticity Grade -->
                    <span class="px-2 py-1 text-xs font-semibold rounded-full
                      @if($product->grade === 'A') bg-green-100 text-green-800
                      @elseif($product->grade === 'B') bg-blue-100 text-blue-800
                      @elseif($product->grade === 'C') bg-yellow-100 text-yellow-800
                      @elseif($product->grade === 'D') bg-orange-100 text-orange-800
                      @else bg-red-100 text-red-800
                      @endif">
                      Grade {{ $product->grade }}
                    </span>
                  </div>

                  <!-- Fake Percentage -->
                  <div class="text-xs text-gray-500">
                    {{ number_format($product->fake_percentage, 1) }}% fake
                  </div>
                </div>

                <!-- Amazon Rating vs Adjusted -->
                @if($product->amazon_rating && $product->adjusted_rating)
                  <div class="mt-2 text-xs text-gray-500">
                    <div class="flex justify-between">
                      <span>Amazon: {{ number_format($product->amazon_rating, 1) }}/5</span>
                      <span>Adjusted: {{ number_format($product->adjusted_rating, 1) }}/5</span>
                    </div>
                  </div>
                @endif

                <!-- Analysis Date -->
                <div class="mt-2 text-xs text-gray-400">
                  Analyzed {{ $product->updated_at->diffForHumans() }}
                </div>
              </a>
            </div>
          @endforeach
        </div>

        <!-- Pagination -->
        <div class="flex justify-center">
          {{ $products->links('pagination::tailwind') }}
        </div>
      @else
        <!-- No Products Found -->
        <div class="text-center py-12">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m14 0v5a2 2 0 01-2 2H6a2 2 0 01-2 2v-5m14 0H6m14 0l-3-3m-3 3l3-3" />
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">No products found</h3>
          <p class="mt-1 text-sm text-gray-500">No analyzed products are available yet.</p>
          <div class="mt-6">
            <a href="/" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700">
              Start analyzing products
            </a>
          </div>
        </div>
      @endif
    </div>
  </main>

  <!-- Footer -->
  <footer class="text-center text-gray-500 text-sm mt-10 p-2">
    <div class="flex items-center justify-center mb-2">
      <a href="/" class="text-teal-600 hover:underline">← Back to Null Fake</a>
    </div>
    <div class="flex items-center justify-center space-x-4 text-xs">
      <a href="https://github.com/stardothosting/nullfake/blob/main/LICENSE" class="text-gray-500 hover:text-gray-700" target="_blank" rel="noopener">MIT License</a>
      <span>•</span>
      <a href="/privacy" class="text-gray-500 hover:text-gray-700">Privacy Policy</a>
    </div>
  </footer>

</div>

<style>
  .line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
</style>

</body>
</html>