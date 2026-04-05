<?php

return [

      /*
      |--------------------------------------------------------------------------
      | Source Locale
      |--------------------------------------------------------------------------
      |
      | The default language used for the original content. 
      | This is the language the Google Translator will translate FROM.
      |
      */
      'source_locale' => 'en',

      /*
      |--------------------------------------------------------------------------
      | Supported Languages
      |--------------------------------------------------------------------------
      |
      | A list of all target locales. The package will iterate through these
      | keys to generate translations for your model fields.
      |
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
      |
      | The name of the queue where translation jobs will be dispatched.
      | Make sure your queue worker is running: php artisan queue:work --queue=translations
      |
      */
      'queue' => 'translations',

      /*
      |--------------------------------------------------------------------------
      | Cache Settings
      |--------------------------------------------------------------------------
      |
      | Duration (in seconds) for which the translations should be cached.
      | Default is 30 days.
      |
      */
      'cache_expiry' => 2592000,

      /*
      |--------------------------------------------------------------------------
      | Auto-Sync Models
      |--------------------------------------------------------------------------
      |
      | List the full class names of models that should be processed when
      | running the `php artisan laraglot:sync` command.
      |
      */
      'models' => [
            // Add your model classes here, e.g., \App\Models\Page::class,
      ],
];