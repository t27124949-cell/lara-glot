<?php

return [

      /*
      |--------------------------------------------------------------------------
      | Source Locale
      |--------------------------------------------------------------------------
      | The locale your content is authored in. LaraGlot will never translate
      | FROM this locale back to itself.
      */
      'source_locale' => 'en',

      /*
      |--------------------------------------------------------------------------
      | Translation Driver
      |--------------------------------------------------------------------------
      | Which translation engine to use. One of:
      |   'google'  — stichoza/google-translate-php (free, no API key needed)
      |   'ollama'  — local LLM via cloudstudio/ollama (requires Ollama running)
      |   'openai'  — OpenAI Chat Completions API (or any compatible endpoint)
      |   'deepl'   — DeepL REST API (free or pro tier)
      */
      'translator' => env('LARAGLOT_DRIVER', 'google'),

      /*
      |--------------------------------------------------------------------------
      | Driver Configuration
      |--------------------------------------------------------------------------
      | Settings specific to each driver. Only the active driver's block is used.
      */
      'drivers' => [

            'google' => [
                  // stichoza/google-translate-php requires no API key.
                  // No additional configuration needed.
            ],

            'ollama' => [
                  // The Ollama model to use. Must be pulled locally first:
                  //   ollama pull llama3
                  //   ollama pull mistral
                  'model' => env('LARAGLOT_OLLAMA_MODEL', 'llama3'),

                  // Number of strings sent per LLM prompt.
                  // Lower = less RAM, more API calls. Higher = more RAM, fewer calls.
                  // 15 is a safe default for an M-series Mac with 16 GB RAM.
                  // Reduce to 5-10 if you still see OOM kills.
                  'chunk_size' => env('LARAGLOT_OLLAMA_CHUNK_SIZE', 15),
            ],

            'openai' => [
                  'api_key' => env('OPENAI_API_KEY'),
                  'model' => env('LARAGLOT_OPENAI_MODEL', 'gpt-4o-mini'),
                  'chunk_size' => env('LARAGLOT_OPENAI_CHUNK_SIZE', 30),

                  // Override base_url to use any OpenAI-compatible endpoint
                  // (e.g. Azure OpenAI, Groq, Together AI, local LM Studio):
                  'base_url' => env('LARAGLOT_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            ],

            'deepl' => [
                  // API key from deepl.com. Free tier keys end with ':fx'.
                  'api_key' => env('DEEPL_API_KEY'),

                  // Auto-detected from key suffix. Override here if needed.
                  // 'base_url' => 'https://api-free.deepl.com/v2',
            ],

      ],

      /*
      |--------------------------------------------------------------------------
      | Excluded Language Files
      |--------------------------------------------------------------------------
      | PHP files inside lang/en/ that should NOT be translated.
      | Typically Laravel's own framework files which ship with official translations.
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
      | All target locales. LaraGlot will never translate back to source_locale.
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
      | Queue
      |--------------------------------------------------------------------------
      | Queue name for TranslateFilesJob and TranslateModelJob.
      | Run worker: php artisan queue:work --queue=translations,default
      */
      'queue' => env('LARAGLOT_QUEUE', 'translations'),

      /*
      |--------------------------------------------------------------------------
      | Cache Expiry
      |--------------------------------------------------------------------------
      | How long (seconds) translated strings are cached. Default: 30 days.
      */
      'cache_expiry' => env('LARAGLOT_CACHE_EXPIRY', 2592000),

      /*
      |--------------------------------------------------------------------------
      | Registered Models
      |--------------------------------------------------------------------------
      | Models processed by `php artisan laraglot:sync`.
      */
      'models' => [
            // \App\Models\Page::class,
            // \App\Models\Post::class,
      ],

];
