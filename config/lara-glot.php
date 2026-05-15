<?php

return [

      /*
      |--------------------------------------------------------------------------
      | Source Locale
      |--------------------------------------------------------------------------
      | The locale your content is authored in. LaraGlot will never translate
      | FROM this locale back to itself.
      */
      'source_locale' => env('LARAGLOT_SOURCE_LOCALE', 'en'),

      /*
      |--------------------------------------------------------------------------
      | Translation Driver
      |--------------------------------------------------------------------------
      | Which translation engine to use. 
      | Options: 'google', 'ollama', 'openai', 'deepl'
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

            'ollama' => [
                  'model' => env('LARAGLOT_OLLAMA_MODEL', 'llama3'),
                  'chunk_size' => (int) env('LARAGLOT_OLLAMA_CHUNK_SIZE', 15),
                  'max_retries' => (int) env('LARAGLOT_OLLAMA_RETRIES', 3),
                  'retry_delay_ms' => (int) env('LARAGLOT_OLLAMA_RETRY_DELAY', 1000), // LLMs need more time
                  'concurrency' => (int) env('LARAGLOT_OLLAMA_CONCURRENCY', 2),
                  'cache_enabled' => (bool) env('LARAGLOT_OLLAMA_CACHE', true),
                  'cache_ttl' => (int) env('LARAGLOT_CACHE_EXPIRY', 2592000),
            ],

            'openai' => [
                  'api_key' => env('OPENAI_API_KEY'),
                  'model' => env('LARAGLOT_OPENAI_MODEL', 'gpt-4o-mini'),
                  'base_url' => env('LARAGLOT_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                  'chunk_size' => (int) env('LARAGLOT_OPENAI_CHUNK_SIZE', 30),
                  'max_retries' => (int) env('LARAGLOT_OPENAI_RETRIES', 3),
                  'retry_delay_ms' => (int) env('LARAGLOT_OPENAI_RETRY_DELAY', 500),
                  'concurrency' => (int) env('LARAGLOT_OPENAI_CONCURRENCY', 3),
                  'cache_enabled' => (bool) env('LARAGLOT_OPENAI_CACHE', true),
                  'cache_ttl' => (int) env('LARAGLOT_CACHE_EXPIRY', 2592000),
            ],

            'deepl' => [
                  'api_key' => env('DEEPL_API_KEY'),
                  'base_url' => env('DEEPL_BASE_URL'), // Leave null for auto-detection (:fx)
                  'chunk_size' => (int) env('LARAGLOT_DEEPL_CHUNK_SIZE', 50),
                  'max_retries' => (int) env('LARAGLOT_DEEPL_RETRIES', 3),
                  'retry_delay_ms' => (int) env('LARAGLOT_DEEPL_RETRY_DELAY', 500),
                  'concurrency' => (int) env('LARAGLOT_DEEPL_CONCURRENCY', 3),
                  'cache_enabled' => (bool) env('LARAGLOT_DEEPL_CACHE', true),
                  'cache_ttl' => (int) env('LARAGLOT_CACHE_EXPIRY', 2592000),
            ],

      ],

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
      | These keys will be skipped during the translation process. 
      | Useful for IDs, slugs, URLs, and technical metadata that 
      | should remain identical across all languages.
      */
      'ignored_keys' => [
            'id',
            'slug',
            'url',
            'image',
            'icon',
            'primary_url',
            'secondary_url',
            'cta_url',
            'autoplay_speed',
            'sort_order',
            'layout_type',
            '_en_original',
            'en_original',
            'external_id',
            'is_crypto',
            'is_deposit_enabled',
            'is_withdrawal_enabled',
            'is_transfer_enabled',
            'is_active',
            'is_automatic',
            'requires_proof',
            'color',
            'currency',
            'uuid',
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