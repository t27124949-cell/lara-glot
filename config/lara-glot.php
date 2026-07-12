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
                  // Strings longer than this are split on sentence boundaries —
                  // the free endpoint 500s intermittently on long GET payloads.
                  'max_length' => (int) env('LARAGLOT_GOOGLE_MAX_LENGTH', 1500),
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
                  'timeout' => (int) env('LARAGLOT_DEEPL_TIMEOUT', 60),
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
                  // Raise for slow reasoning models; lower chunk_size helps too.
                  'timeout' => (int) env('LARAGLOT_OPENAI_TIMEOUT', 120),
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
                  'timeout' => (int) env('LARAGLOT_ANTHROPIC_TIMEOUT', 120),
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
      | Keys skipped during translation — matched against model attribute
      | names AND the last dot-segment of language-file keys. Identifiers,
      | media paths, machine values, and ordering fields that must stay
      | identical across languages.
      |
      | ⚠️ Only list keys that are never a human-readable label. A key like
      | 'email' or 'price' is often a translatable label in language files
      | ('email' => 'Email Address'), so words like those do NOT belong here.
      */
      'ignored_keys' => [
            // Identifiers
            'id', 'uuid', 'ulid', 'external_id', 'reference_id', 'parent_id',
            'slug', 'sku', 'barcode', 'ean', 'isbn',

            // Machine/security values
            'token', 'api_key', 'secret', 'hash', 'password', 'remember_token',
            'ip_address', 'mac_address', 'user_agent',

            // Media & paths
            'url', 'image', 'icon', 'logo', 'favicon', 'avatar', 'thumbnail',
            'banner', 'video_url', 'file_path', 'image_path', 'path', 'src', 'href',
            'mime_type',

            // Locale/format config
            'locale', 'language_code', 'currency_code', 'country_code',
            'timezone', 'date_format', 'time_format', 'datetime_format',

            // Geo & ordering
            'latitude', 'longitude', 'lat', 'lng', 'coordinates',
            'sort_order', 'sort', 'color', 'hex',

            // Timestamps
            'created_at', 'updated_at', 'deleted_at', 'published_at',
      ],

      /*
      |--------------------------------------------------------------------------
      | Protected Value Patterns  (file translation)
      |--------------------------------------------------------------------------
      | Regex patterns matched against each VALUE in a language file. Matching
      | values are passed through untranslated — references like
      | 'route:contact', bare URLs, or mailto:/tel: links would otherwise be
      | destroyed by translation (e.g. 'route:contact' → 'الطريق: الاتصال').
      | Independent of ignored_keys, which matches attribute/key names.
      */
      'protected_value_patterns' => [
            '/^route:/',
            '/^https?:\/\/\S+$/',
            '/^mailto:/',
            '/^tel:/',
      ],

      /*
      |--------------------------------------------------------------------------
      | Fallback Detection
      |--------------------------------------------------------------------------
      | When a driver fails, individual strings fall back to the source text.
      | fail_on_full_fallback: when EVERY translatable string in a file comes
      | back identical to the source, throw instead of writing an untranslated
      | file that looks done. Disable only if a locale is legitimately
      | identical to the source.
      | warn_ratio: log a warning (and flag in laraglot:files --sync output)
      | when at least this share of a file's strings are identical to source.
      */
      'fallback' => [
            'fail_on_full_fallback' => (bool) env('LARAGLOT_FAIL_ON_FULL_FALLBACK', true),
            'warn_ratio' => (float) env('LARAGLOT_FALLBACK_WARN_RATIO', 0.5),
      ],

      /*
      |--------------------------------------------------------------------------
      | Retry Queue  (laraglot:retry)
      |--------------------------------------------------------------------------
      | Values that fall back to source text are recorded in the
      | lara_glot_translation_retries table and re-attempted by the
      | laraglot:retry command with exponential back-off
      | (base_delay_minutes × 2^attempts). After max_attempts the unit is
      | parked as "exhausted" and surfaced for human review — a string that
      | will never translate must not re-bill forever.
      */
      'retry' => [
            'max_attempts' => (int) env('LARAGLOT_RETRY_MAX_ATTEMPTS', 5),
            'base_delay_minutes' => (int) env('LARAGLOT_RETRY_BASE_DELAY', 30),
      ],

      /*
      |--------------------------------------------------------------------------
      | Audit  (laraglot:audit)
      |--------------------------------------------------------------------------
      | threshold: a file/model locale whose eligible values are at least this
      | share identical to the source locale is flagged (exit code 1) — the
      | signature of a silent translation fallback.
      |
      | allowlist: exact values that are SUPPOSED to be identical in every
      | language ("OK", "SMS", brand names). Never flagged, never re-billed
      | by --repair.
      */
      'audit' => [
            'threshold' => (float) env('LARAGLOT_AUDIT_THRESHOLD', 0.85),

            // Exact-match values (case-sensitive, compared against the SOURCE
            // value) that are expected to be identical in every language.
            // Acronyms, units, formats, and brand/platform names — extend
            // freely in the published config.
            'allowlist' => [
                  // Interjections & symbols
                  'OK',

                  // Tech & format acronyms
                  'SMS', 'MMS', 'API', 'URL', 'ID', 'PDF', 'CSV', 'XML', 'JSON',
                  'HTML', 'HTTP', 'HTTPS', 'FTP', 'SSL', 'TLS', 'IP', 'DNS',
                  'QR', 'RSS', 'SEO', 'CMS', 'CRM', 'ERP', 'SDK', 'AI', 'FAQ',
                  'GPS', 'USB', 'RAM', 'CPU', 'GPU', 'LED', 'LCD', 'HD', '4K',
                  'Wi-Fi', 'WiFi', 'Bluetooth', 'NFC', 'VPN', 'PIN', 'OTP', '2FA', 'CVV',

                  // Business & finance
                  'VAT', 'IBAN', 'BIC', 'SWIFT', 'SKU', 'EAN', 'ISBN', 'B2B', 'B2C',
                  'USD', 'EUR', 'GBP', 'CHF', 'JPY', 'CNY', 'AED', 'SAR',
                  'CEO', 'CTO', 'CFO', 'HR', 'IT', 'PR',

                  // Units
                  'kg', 'g', 'mg', 'km', 'm', 'cm', 'mm', 'ml', 'l',
                  'KB', 'MB', 'GB', 'TB', 'px', 'ms',

                  // Platforms & brands
                  'iOS', 'Android', 'Windows', 'macOS', 'Linux',
                  'Google', 'Apple', 'Microsoft', 'Amazon', 'Meta',
                  'Facebook', 'Instagram', 'Twitter', 'YouTube', 'LinkedIn',
                  'WhatsApp', 'Telegram', 'TikTok', 'Snapchat', 'Pinterest',
                  'PayPal', 'Stripe', 'Visa', 'Mastercard', 'American Express',
                  'Apple Pay', 'Google Pay', 'Zoom', 'Slack', 'GitHub', 'Chrome',
                  'Safari', 'Firefox', 'Excel', 'Word', 'PowerPoint', 'Outlook',
                  'Gmail', 'Netflix', 'Spotify', 'Airbnb', 'Uber', 'Laravel',
            ],
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
