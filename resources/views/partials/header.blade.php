<header class="bg-white shadow p-4">
  <div class="max-w-4xl mx-auto flex items-center justify-between">
    <!-- Logo -->
    <div class="flex items-center space-x-3">
      <a href="{{ route('home') }}">
        <img src="/img/nullfake.svg" alt="Null Fake Logo" class="h-20 w-auto object-contain" />
      </a>
    </div>

    <!-- Desktop Navigation -->
    <nav class="hidden md:flex items-center space-x-6">
      <a href="{{ route('home') }}" class="text-gray-600 hover:text-gray-900 transition-colors {{ request()->routeIs('home') ? 'text-indigo-600 font-medium' : '' }}">Home</a>
      <a href="/products" class="text-gray-600 hover:text-gray-900 transition-colors {{ request()->routeIs('products.*') ? 'text-indigo-600 font-medium' : '' }}">All Products</a>
      <a href="/how-it-works" class="text-gray-600 hover:text-gray-900 transition-colors {{ request()->is('how-it-works') ? 'text-indigo-600 font-medium' : '' }}">How It Works</a>
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

    <!-- Mobile Hamburger Button -->
    <button id="mobile-menu-button" class="md:hidden flex items-center justify-center w-10 h-10 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors">
      <svg id="hamburger-icon" class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
      </svg>
      <svg id="close-icon" class="w-6 h-6 text-gray-600 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
      </svg>
    </button>
  </div>

  <!-- Mobile Navigation Menu -->
  <div id="mobile-menu" class="md:hidden mobile-menu mt-4 pb-4 border-t border-gray-200">
    <nav class="flex flex-col space-y-4 pt-4">
      <a href="{{ route('home') }}" class="text-gray-600 hover:text-gray-900 transition-colors px-4 py-2 {{ request()->routeIs('home') ? 'text-indigo-600 font-medium bg-indigo-50' : '' }}">Home</a>
      <a href="/products" class="text-gray-600 hover:text-gray-900 transition-colors px-4 py-2 {{ request()->routeIs('products.*') ? 'text-indigo-600 font-medium bg-indigo-50' : '' }}">All Products</a>
      <a href="/how-it-works" class="text-gray-600 hover:text-gray-900 transition-colors px-4 py-2 {{ request()->is('how-it-works') ? 'text-indigo-600 font-medium bg-indigo-50' : '' }}">How It Works</a>
      <a href="{{ route('contact.show') }}" class="text-gray-600 hover:text-gray-900 transition-colors px-4 py-2 {{ request()->routeIs('contact.*') ? 'text-indigo-600 font-medium bg-indigo-50' : '' }}">Contact</a>
      <a href="https://chromewebstore.google.com/detail/null-fake-product-review/fgngdgdpfcfkddgipaafgpnadiikfkaa" 
         target="_blank" 
         rel="noopener noreferrer"
         class="bg-extension hover:bg-extension-dark text-white px-4 py-3 rounded-lg font-medium transition-colors flex items-center justify-center space-x-2 mx-4"
         title="Install Chrome Extension">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
        </svg>
        <span>Chrome Extension</span>
      </a>
    </nav>
  </div>
</header>
