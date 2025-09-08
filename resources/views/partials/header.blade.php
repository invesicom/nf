<header class="bg-white shadow p-4">
  <div class="max-w-4xl mx-auto flex items-center justify-between">
    <div class="flex items-center pr-4 md:pr-0 space-x-3">
      <a href="{{ route('home') }}">
        <img src="/img/nullfake.svg" alt="Null Fake Logo" class="h-20 w-auto object-contain" />
      </a>
    </div>
    <nav class="flex space-x-6">
      <a href="{{ route('home') }}" class="text-gray-600 hover:text-gray-900 transition-colors {{ request()->routeIs('home') ? 'text-indigo-600 font-medium' : '' }}">Home</a>
      <a href="/products" class="text-gray-600 hover:text-gray-900 transition-colors {{ request()->routeIs('products.*') ? 'text-indigo-600 font-medium' : '' }}">All Products</a>
      <a href="{{ route('contact.show') }}" class="text-gray-600 hover:text-gray-900 transition-colors {{ request()->routeIs('contact.*') ? 'text-indigo-600 font-medium' : '' }}">Contact</a>
    </nav>
  </div>
</header>
