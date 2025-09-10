<header class="bg-white shadow p-4">
  <div class="max-w-4xl mx-auto flex items-center justify-between">
    <div class="flex items-center pr-4 md:pr-0 space-x-3">
      <a href="{{ route('home') }}">
        <img src="/img/nullfake.svg" alt="Null Fake Logo" class="h-20 w-auto object-contain" />
      </a>
    </div>
    <nav class="flex items-center space-x-6">
      <a href="{{ route('home') }}" class="text-gray-600 hover:text-gray-900 transition-colors {{ request()->routeIs('home') ? 'text-indigo-600 font-medium' : '' }}">Home</a>
      <a href="/products" class="text-gray-600 hover:text-gray-900 transition-colors {{ request()->routeIs('products.*') ? 'text-indigo-600 font-medium' : '' }}">All Products</a>
      <a href="{{ route('contact.show') }}" class="text-gray-600 hover:text-gray-900 transition-colors {{ request()->routeIs('contact.*') ? 'text-indigo-600 font-medium' : '' }}">Contact</a>
      <a href="https://chromewebstore.google.com/detail/null-fake-product-review/fgngdgdpfcfkddgipaafgpnadiikfkaa" 
         target="_blank" 
         rel="noopener noreferrer"
         class="bg-extension hover:bg-extension-dark text-white px-4 py-2 rounded-lg font-medium transition-colors flex items-center space-x-2"
         title="Install Chrome Extension">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
        </svg>
        <span>Chrome Extension</span>
      </a>
    </nav>
  </div>
</header>
