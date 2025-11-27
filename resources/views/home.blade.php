<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $seoData['meta_title'] ?? 'Null Fake - Amazon Review Analysis' }}</title>
  <meta name="description" content="{{ $seoData['meta_description'] ?? 'AI-powered Amazon review analysis across 14+ countries. Detect fake reviews instantly.' }}" />
  <meta name="keywords" content="{{ $seoData['keywords'] ?? 'amazon review analysis, fake review detector, ai review analysis' }}" />
  <meta name="author" content="shift8 web" />
  
  <!-- SEO and Robots Configuration -->
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
  <meta name="googlebot" content="index, follow" />
  <meta name="bingbot" content="index, follow" />
  
  <link rel="canonical" href="{{ url('/') }}" />
  <link rel="sitemap" type="application/xml" href="{{ url('/sitemap.xml') }}" />

  <!-- Favicon and App Icons -->
  <link rel="apple-touch-icon" sizes="57x57" href="/img/apple-icon-57x57.png">
  <link rel="apple-touch-icon" sizes="60x60" href="/img/apple-icon-60x60.png">
  <link rel="apple-touch-icon" sizes="72x72" href="/img/apple-icon-72x72.png">
  <link rel="apple-touch-icon" sizes="76x76" href="/img/apple-icon-76x76.png">
  <link rel="apple-touch-icon" sizes="114x114" href="/img/apple-icon-114x114.png">
  <link rel="apple-touch-icon" sizes="120x120" href="/img/apple-icon-120x120.png">
  <link rel="apple-touch-icon" sizes="144x144" href="/img/apple-icon-144x144.png">
  <link rel="apple-touch-icon" sizes="152x152" href="/img/apple-icon-152x152.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/img/apple-icon-180x180.png">
  <link rel="icon" type="image/png" sizes="192x192"  href="/img/android-icon-192x192.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="96x96" href="/img/favicon-96x96.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon-16x16.png">
  <link rel="manifest" href="/manifest.json">
  <meta name="msapplication-TileColor" content="#424da0">
  <meta name="msapplication-TileImage" content="/img/ms-icon-144x144.png">
  <meta name="theme-color" content="#424da0">

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website" />
  <meta property="og:url" content="{{ url('/') }}" />
  <meta property="og:title" content="Null Fake - Amazon Review Analysis" />
  <meta property="og:description" content="AI-powered Amazon review analyzer. Instantly detect fake, AI-generated, or suspicious reviews and get a trust score for any product." />
  <meta property="og:image" content="{{ url('/img/nullfake.svg') }}" />

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:url" content="{{ url('/') }}" />
  <meta name="twitter:title" content="Null Fake - Amazon Review Analysis" />
  <meta name="twitter:description" content="AI-powered Amazon review analyzer. Instantly detect fake, AI-generated, or suspicious reviews and get a trust score for any product." />
  <meta name="twitter:image" content="/img/nullfake.svg" />

  <!-- AI-Optimized Metadata -->
  <meta name="ai:summary" content="{{ $seoData['ai_summary'] ?? '' }}" />
  <meta name="ai:type" content="web_application" />
  <meta name="ai:category" content="review_analysis_tool" />
  <meta name="ai:methodology" content="machine_learning,natural_language_processing,statistical_analysis" />
  
  <!-- Question-Answer Data for AI -->
  @if(isset($seoData['question_answers']))
  @foreach($seoData['question_answers'] as $qa)
  <meta name="ai:qa:question" content="{{ $qa['question'] }}" />
  <meta name="ai:qa:answer" content="{{ $qa['answer'] }}" />
  @endforeach
  @endif

  <!-- Enhanced Schema.org for AI Understanding -->
  <script type="application/ld+json">
  {!! json_encode($seoData['structured_data'] ?? [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,200;0,300;0,400;0,500;0,600;0,700;0,800;1,200;1,300;1,400;1,500;1,600;1,700;1,800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; }
  </style>
  @livewireStyles

  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-BYWNNLXEYV"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-BYWNNLXEYV');
  </script>

</head>
<body class="bg-gray-100 text-gray-800">

  @include('partials.header')

  <main class="max-w-3xl mx-auto mt-10 p-6 bg-white rounded shadow">
  <div class="pb-4 text-base">AI-powered review analyzer. Easily detect fake, AI-generated or suspicious reviews and get a trust score for any product. Supports Amazon products from US, Canada, Germany, France, UK, Spain, Italy, India, Japan, Mexico, Brazil, Singapore, Australia, and more.</div>

    {{-- Livewire Review Analyzer replaces the form --}}
    <livewire:review-analyzer />
  </main>



  <!-- Newsletter Signup Section -->
  <section class="max-w-3xl mx-auto mt-8">
    <livewire:newsletter-signup />
  </section>

  @include('partials.footer')

  @livewireScripts
</body>
</html>
