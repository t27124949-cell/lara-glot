<?php

return [

      /*
      |--------------------------------------------------------------------------
      | Source Locale
      |--------------------------------------------------------------------------
      | The locale your content is authored in. LaraGlot never translates
      | from this locale back to itself.
      */
      'source_locale' => env('LARAGLOT_SOURCE_LOCALE', 'en'),

      /*
      |--------------------------------------------------------------------------
      | Translation Driver
      |--------------------------------------------------------------------------
      | Options: 'google', 'deepl', 'openai', 'anthropic', 'ollama',
      | or any name registered via TranslationService::extend().
      */
      'translator' => env('LARAGLOT_DRIVER', 'google'),

      /*
      |--------------------------------------------------------------------------
      | Driver Configuration
      |--------------------------------------------------------------------------
      */
      'drivers' => [

            'google' => [
                  'max_retries' => (int) env('LARAGLOT_GOOGLE_RETRIES', 3),
                  'retry_delay_ms' => (int) env('LARAGLOT_GOOGLE_RETRY_DELAY', 300),
                  'concurrency' => (int) env('LARAGLOT_GOOGLE_CONCURRENCY', 5),
                  'batch_delay_ms' => (int) env('LARAGLOT_GOOGLE_BATCH_DELAY', 100),
                  'cache_enabled' => (bool) env('LARAGLOT_GOOGLE_CACHE', true),
                  'cache_ttl' => (int) env('LARAGLOT_CACHE_EXPIRY', 2592000),
            ],

            'deepl' => [
                  'api_key' => env('DEEPL_API_KEY'),
                  'base_url' => env('DEEPL_BASE_URL'), // null = auto-detect free (:fx) vs pro keys
                  'chunk_size' => (int) env('LARAGLOT_DEEPL_CHUNK_SIZE', 50),
                  'max_retries' => (int) env('LARAGLOT_DEEPL_RETRIES', 3),
                  'retry_delay_ms' => (int) env('LARAGLOT_DEEPL_RETRY_DELAY', 500),
                  'concurrency' => (int) env('LARAGLOT_DEEPL_CONCURRENCY', 3),
                  'cache_enabled' => (bool) env('LARAGLOT_DEEPL_CACHE', true),
                  'cache_ttl' => (int) env('LARAGLOT_CACHE_EXPIRY', 2592000),
            ],

            'openai' => [
                  'api_key' => env('OPENAI_API_KEY'),
                  'model' => env('LARAGLOT_OPENAI_MODEL', 'gpt-5-mini'),
                  'base_url' => env('LARAGLOT_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                  'chunk_size' => (int) env('LARAGLOT_OPENAI_CHUNK_SIZE', 30),
                  'max_retries' => (int) env('LARAGLOT_OPENAI_RETRIES', 3),
                  'retry_delay_ms' => (int) env('LARAGLOT_OPENAI_RETRY_DELAY', 500),
                  'concurrency' => (int) env('LARAGLOT_OPENAI_CONCURRENCY', 3),
                  'cache_enabled' => (bool) env('LARAGLOT_OPENAI_CACHE', true),
                  'cache_ttl' => (int) env('LARAGLOT_CACHE_EXPIRY', 2592000),
            ],

            'anthropic' => [
                  'api_key' => env('ANTHROPIC_API_KEY'),
                  // claude-haiku-4-5 is fast and cheap for volume work;
                  // switch to claude-sonnet-5 for maximum nuance.
                  'model' => env('LARAGLOT_ANTHROPIC_MODEL', 'claude-haiku-4-5'),
                  'base_url' => env('LARAGLOT_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
                  'max_tokens' => (int) env('LARAGLOT_ANTHROPIC_MAX_TOKENS', 8192),
                  'chunk_size' => (int) env('LARAGLOT_ANTHROPIC_CHUNK_SIZE', 30),
                  'max_retries' => (int) env('LARAGLOT_ANTHROPIC_RETRIES', 3),
                  'retry_delay_ms' => (int) env('LARAGLOT_ANTHROPIC_RETRY_DELAY', 500),
                  'concurrency' => (int) env('LARAGLOT_ANTHROPIC_CONCURRENCY', 3),
                  'cache_enabled' => (bool) env('LARAGLOT_ANTHROPIC_CACHE', true),
                  'cache_ttl' => (int) env('LARAGLOT_CACHE_EXPIRY', 2592000),
            ],

            'ollama' => [
                  'model' => env('LARAGLOT_OLLAMA_MODEL', 'llama3.2'),
                  'chunk_size' => (int) env('LARAGLOT_OLLAMA_CHUNK_SIZE', 15),
                  'max_retries' => (int) env('LARAGLOT_OLLAMA_RETRIES', 3),
                  'retry_delay_ms' => (int) env('LARAGLOT_OLLAMA_RETRY_DELAY', 1000),
                  'concurrency' => (int) env('LARAGLOT_OLLAMA_CONCURRENCY', 2),
                  'cache_enabled' => (bool) env('LARAGLOT_OLLAMA_CACHE', true),
                  'cache_ttl' => (int) env('LARAGLOT_CACHE_EXPIRY', 2592000),
            ],

      ],

      /*
      |--------------------------------------------------------------------------
      | Glossary
      |--------------------------------------------------------------------------
      | protected_terms: never translated — kept byte-identical in every
      | language (brand names, product names). Works with all drivers.
      |
      | terms: forced translations per target locale. Applied by the LLM
      | drivers (openai, anthropic, ollama); Google and DeepL ignore them.
      |
      | Changing the glossary automatically invalidates cached LLM
      | translations, so stale wording never lingers.
      */
      'glossary' => [
            'protected_terms' => [
                  // 'LaraGlot',
            ],
            'terms' => [
                  // 'checkout' => ['de' => 'Kasse', 'fr' => 'paiement'],
            ],
      ],

      /*
      |--------------------------------------------------------------------------
      | Quality Review Pass
      |--------------------------------------------------------------------------
      | When enabled, LLM drivers run a second pass over each translated
      | chunk to fix stiff or overly literal phrasing before caching. Doubles
      | the API calls for uncached strings; the reviewed result is what gets
      | cached, so the cost is paid once per string, ever. If the review call
      | fails the first draft is kept — a translation is never lost.
      */
      'review' => [
            'enabled' => (bool) env('LARAGLOT_REVIEW', false),
      ],

      /*
      |--------------------------------------------------------------------------
      | Prompt Override
      |--------------------------------------------------------------------------
      | Set to a string to fully replace the built-in LLM system prompt.
      | {source} and {target} are substituted with the locale codes.
      */
      'prompt' => null,

      /*
      |--------------------------------------------------------------------------
      | Excluded Language Files
      |--------------------------------------------------------------------------
      */
      'exclude_files' => [
            'auth',
            'pagination',
            'passwords',
            'validation',
      ],

      /*
      |--------------------------------------------------------------------------
      | Supported Languages
      |--------------------------------------------------------------------------
      */
      'languages' => [
            'en' => ['name' => 'English', 'flag' => '🇬🇧'],
            'es' => ['name' => 'Español', 'flag' => '🇪🇸'],
            'fr' => ['name' => 'Français', 'flag' => '🇫🇷'],
            'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪'],
            'pt' => ['name' => 'Português', 'flag' => '🇵🇹'],
            'ar' => ['name' => 'العربية', 'flag' => '🇸🇦'],
            'zh' => ['name' => '中文', 'flag' => '🇨🇳'],
            'hi' => ['name' => 'हिन्दी', 'flag' => '🇮🇳'],
            'ak' => ['name' => 'Twi', 'flag' => '🇬🇭'],
            'yo' => ['name' => 'Yorùbá', 'flag' => '🇳🇬'],
            'it' => ['name' => 'Italiano', 'flag' => '🇮🇹'],
            'nl' => ['name' => 'Nederlands', 'flag' => '🇳🇱'],
            'ru' => ['name' => 'Русский', 'flag' => '🇷🇺'],
            'ja' => ['name' => '日本語', 'flag' => '🇯🇵'],
            'tr' => ['name' => 'Türkçe', 'flag' => '🇹🇷'],
            'ko' => ['name' => '한국어', 'flag' => '🇰🇷'],
            'sw' => ['name' => 'Kiswahili', 'flag' => '🇰🇪'],
            'id' => ['name' => 'Bahasa Indonesia', 'flag' => '🇮🇩'],
            'vi' => ['name' => 'Tiếng Việt', 'flag' => '🇻🇳'],
            'pl' => ['name' => 'Polski', 'flag' => '🇵🇱'],
      ],

      /*
      |--------------------------------------------------------------------------
      | Queue Configuration
      |--------------------------------------------------------------------------
      */
      'queue' => env('LARAGLOT_QUEUE', 'translations'),

      /*
      |--------------------------------------------------------------------------
      | Ignored Attribute Keys
      |--------------------------------------------------------------------------
      | Attribute keys skipped during model translation: identifiers, media
      | paths, and ordering fields that must stay identical across languages.
      | Add your own app-specific keys in the published config.
      */
      'ignored_keys' => [
            'id',
            'uuid',
            'external_id',
            'slug',
            'url',
            'image',
            'icon',
            'color',
            'sort_order',
      ],

      /*
      |--------------------------------------------------------------------------
      | Global Cache Expiry
      |--------------------------------------------------------------------------
      | Default: 30 days (2,592,000 seconds).
      */
      'cache_expiry' => env('LARAGLOT_CACHE_EXPIRY', 2592000),

      /*
      |--------------------------------------------------------------------------
      | Registered Models
      |--------------------------------------------------------------------------
      */
      'models' => [
            // \App\Models\Page::class,
      ],

];
