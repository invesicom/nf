# Null Fake

<div align="center">
  <img src="https://nullfake.com/img/nullfake.png" alt="Null Fake Logo" width="200">
</div>

A Laravel application that analyzes Amazon product reviews to detect fake reviews using AI. The service supports multiple data collection methods including BrightData's managed web scraping service, direct Amazon scraping, and comprehensive AI analysis with multi-provider support.

Visit [nullfake.com](https://nullfake.com) to try it out.

Read our [blog post about how nullfake works](https://shift8web.ca/from-fakespot-to-null-fake-navigating-the-evolving-landscape-of-fake-reviews/)

## Table of Contents

- [How It Works](#how-it-works)
- [Features](#features)
  - [Review Collection](#review-collection)
  - [AI Analysis](#ai-analysis)
  - [User Experience](#user-experience)
  - [Infrastructure](#infrastructure)
- [Data Collection Methods](#data-collection-methods)
  - [BrightData Web Scraper](#brightdata-web-scraper-recommended)
  - [Direct Amazon Scraping](#direct-amazon-scraping)
  - [AJAX Bypass](#ajax-bypass-experimental)
- [Database Schema](#database-schema)
- [Technology Stack](#technology-stack)
- [Configuration](#configuration)
  - [LLM Provider Setup](#llm-provider-setup)
  - [Asynchronous Processing](#asynchronous-processing)
- [Management Commands](#management-commands)
  - [LLM Management](#llm-management)
  - [Data Processing](#data-processing)
  - [Session Management](#session-management)
  - [Queue Processing](#queue-processing)
- [Usage](#usage)
- [Development](#development)
  - [Testing](#testing)
  - [Code Style](#code-style)
- [License](#license)
- [Shift8](#shift8)

## How It Works

1. User submits an Amazon product URL and completes a captcha
2. Null Fake retrieves the ASIN and country from the URL
3. Database check: If a review analysis for the given ASIN and country exists in the database (and is less than 30 days old), the cached analysis is returned instantly
4. If fresh data is needed, the service fetches reviews using the configured data collection method (BrightData, direct scraping, or AJAX bypass)
5. Reviews are analyzed using AI with configurable thresholds for fake review detection
6. Results are displayed including fake review percentage, grade, explanation, and ratings

## Features

### Review Collection
- **BrightData Integration**: Professional web scraping with managed infrastructure and anti-bot protection
- **Direct Amazon Scraping**: Custom scraping with proxy support and session management
- **AJAX Bypass**: Alternative method using Amazon's internal endpoints
- **Multi-session Management**: Cookie rotation across multiple Amazon sessions for reliability
- **Rate Limiting**: Configurable delays and request throttling

### AI Analysis
- **Multi-provider Support**: OpenAI, DeepSeek, or self-hosted Ollama
- **Configurable Thresholds**: Adjustable fake review detection sensitivity (default: 85+ score)
- **Comprehensive Scoring**: Heuristic analysis combined with LLM evaluation
- **Grade System**: Letter grades (A-F) with detailed explanations

### User Experience
- **Real-time Progress**: Job-based processing with live progress updates
- **Asynchronous Processing**: Queue-based analysis for better performance
- **Captcha Protection**: reCAPTCHA and hCaptcha support with session persistence
- **Product Metadata**: Title, image, and description extraction for complete product pages
- **Shareable URLs**: SEO-optimized product analysis pages

### Infrastructure
- **Database Caching**: Fast repeat lookups with 30-day cache validity
- **Comprehensive Alerting**: Pushover notifications for API errors and system issues
- **Command Line Tools**: Management commands for data processing and system maintenance
- **Test Coverage**: Extensive test suite with 396+ tests covering all major functionality

## Data Collection Methods

The application supports three primary methods for collecting Amazon review data:

### BrightData Web Scraper (Recommended)

Professional managed scraping service with enterprise-grade infrastructure:

```bash
AMAZON_REVIEW_SERVICE=brightdata
BRIGHTDATA_SCRAPER_API=your-api-key-here
```

Benefits:
- Managed anti-bot protection
- High success rates for data collection
- Professional infrastructure
- Built-in retry mechanisms

### Direct Amazon Scraping

Custom scraping implementation with advanced session management:

```bash
AMAZON_REVIEW_SERVICE=scraping
AMAZON_COOKIES_1=your-session-cookies-here
AMAZON_COOKIES_2=your-session-cookies-here
# Up to AMAZON_COOKIES_10 for rotation
```

Features:
- Multi-session cookie rotation
- CAPTCHA detection and alerting
- Proxy integration support
- Bandwidth optimization

### AJAX Bypass (Experimental)

Alternative method using Amazon's internal AJAX endpoints:

```bash
AMAZON_REVIEW_SERVICE=ajax
```

Note: Currently disabled pending optimization. Uses Amazon's review rendering endpoints to bypass traditional page scraping.

## Database Schema

The `asin_data` table stores:
- `asin` - Amazon Standard Identification Number
- `country` - Country code (e.g., 'us', 'ca')
- `product_title` - Product title from Amazon
- `product_description` - Product description from Amazon
- `product_image_url` - Product image URL
- `reviews` - JSON array of fetched reviews
- `openai_result` - JSON of full AI analysis with detailed scores
- `total_reviews_on_amazon` - Total review count reported by Amazon
- `have_product_data` - Boolean indicating complete product metadata

The model calculates:
- `fake_percentage` - Percentage of reviews flagged as potentially fake (score ≥ 85)
- `grade` - Letter grade (A-F) based on fake review percentage
- `explanation` - Human-readable analysis summary
- `amazon_rating` - Original average rating from all reviews
- `adjusted_rating` - Adjusted rating excluding fake reviews

## Technology Stack

- **Backend**: Laravel 12 with Livewire 3
- **Database**: MySQL/PostgreSQL with JSON columns
- **Queue System**: Database-driven job processing for asynchronous analysis
- **Session Management**: Multi-provider session rotation for Amazon access
- **AI Integration**: Multi-provider LLM support with automatic failover
- **Security**: reCAPTCHA/hCaptcha integration with session persistence
- **Monitoring**: Comprehensive alerting via Pushover for system health

## Configuration

### LLM Provider Setup

The application supports three AI providers for review analysis:

#### Option 1: OpenAI (Default)
```bash
LLM_PRIMARY_PROVIDER=openai
OPENAI_API_KEY=sk-proj-your-openai-key-here
OPENAI_MODEL=gpt-4o-mini
```

#### Option 2: DeepSeek (94% cost reduction vs OpenAI)
```bash
LLM_PRIMARY_PROVIDER=deepseek
DEEPSEEK_API_KEY=sk-your-deepseek-key-here
DEEPSEEK_MODEL=deepseek-v3
```

#### Option 3: Self-Hosted Ollama (100% cost reduction)
1. Install Ollama: `curl -fsSL https://ollama.com/install.sh | sh`
2. Pull a model: `ollama pull qwen2.5:7b` or `ollama pull llama3.2:3b`
3. Configure:
```bash
LLM_PRIMARY_PROVIDER=ollama
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=qwen2.5:7b
```

#### Multi-Provider Fallback
```bash
LLM_AUTO_FALLBACK=true
```

### Asynchronous Processing

Control job-based vs immediate processing:

```bash
ANALYSIS_ASYNC_ENABLED=true  # Use job queues (recommended for production)
QUEUE_CONNECTION=database    # Database-backed job processing
```

For immediate processing (development):
```bash
ANALYSIS_ASYNC_ENABLED=false
```

## Management Commands

### LLM Management
- Check provider status: `php artisan llm:manage status`
- Switch providers: `php artisan llm:manage switch --provider=ollama`
- Compare costs: `php artisan llm:manage costs --reviews=100`
- Test providers: `php artisan llm:manage test`

### Data Processing
- Process existing products: `php artisan asin:process-existing --missing-any`
- Clean zero-review products: `php artisan products:cleanup-zero-reviews`
- Backfill total review counts: `php artisan backfill:total-review-counts`

### Session Management
- Check Amazon sessions: `php artisan amazon:cookie-sessions`
- Test scraping functionality: `php artisan test:amazon-scraping`
- Debug proxy connections: `php artisan debug:amazon-scraping`

### Queue Processing
- Start queue worker: `php artisan queue:work --queue=analysis`
- Restart workers: `php artisan queue:restart`

## Usage

1. Enter an Amazon product URL
2. Complete the captcha (production only)
3. View real-time progress as analysis processes
4. Review detailed results including:
   - Fake review percentage with adjustable thresholds
   - Letter grade (A-F) based on authenticity
   - Detailed explanation of findings
   - Original vs adjusted ratings
   - Product metadata and images

## Development

### Testing
Run the comprehensive test suite:
```bash
php artisan test
```

The application includes 396+ tests covering:
- Unit tests for all major services
- Feature tests for user workflows
- Integration tests for external services
- Mock-based testing to prevent external API calls

### Code Style
Maintain code quality with Laravel Pint:
```bash
./vendor/bin/pint
```

## License

MIT

## Shift8

Developed in Toronto, Canada by [Shift8 Web](https://shift8web.ca)