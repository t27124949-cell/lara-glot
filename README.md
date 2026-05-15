# LaraGlot 🌍

**Smart Auto-Translation for Laravel Models, Language Files & Nested Content**

LaraGlot is an industrial-strength translation pipeline for Laravel applications. It intelligently translates:

* Eloquent models
* Nested JSON structures
* Filament Page Builder sections
* Static PHP language files
* SEO metadata
* Rich HTML content

…while protecting placeholders, HTML, IDs, slugs, application logic, and structural data from being corrupted by translation engines or LLMs.

Built for modern Laravel applications, LaraGlot combines:

* Queue-based background processing
* Smart recursive traversal
* Multi-layer caching
* AI-powered translation drivers
* Filament admin tooling
* Retry & timeout protection
* Batch translation optimization

---

# Table of Contents

* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Driver Architecture](#driver-architecture)
* [Model Setup](#model-setup)
* [Language File Translation](#language-file-translation)
* [Queue Setup & Timeouts](#queue-setup--timeouts)
* [Artisan Commands](#artisan-commands)
* [Filament Integration](#filament-integration)
* [Caching Strategy](#caching-strategy)
* [Architecture](#architecture)
* [Service Responsibilities](#service-responsibilities)
* [Logging & Monitoring](#logging--monitoring)
* [Failure Recovery](#failure-recovery)
* [Performance Optimizations](#performance-optimizations)
* [License](#license)

---

# Requirements

| Requirement                 | Version          |
| --------------------------- | ---------------- |
| PHP                         | `^8.1`           |
| Laravel                     | `^10.0 \| ^11.0` |
| Filament                    | `^3.0`           |
| spatie/laravel-translatable | `^6.0`           |

---

# Installation

```bash
composer require tonydev/lara-glot
```

---

## Publish the Config

```bash
php artisan vendor:publish --tag=lara-glot-config
```

---

## Register the Commands

### Laravel 10

Register commands inside `app/Console/Kernel.php`:

```php
protected $commands = [
    \Tonydev\LaraGlot\Commands\TranslateFilesCommand::class,
    \Tonydev\LaraGlot\Commands\DispatchTranslations::class,
];
```

---

### Laravel 11

Register commands inside `bootstrap/app.php`:

```php
->withCommands([
    \Tonydev\LaraGlot\Commands\TranslateFilesCommand::class,
    \Tonydev\LaraGlot\Commands\DispatchTranslations::class,
])
```

---

# Configuration

After publishing, open:

```php
config/lara-glot.php
```

Example configuration:

```php
return [

    /*
    |--------------------------------------------------------------------------
    | Source Locale
    |--------------------------------------------------------------------------
    */
    'source_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Translation Driver
    |--------------------------------------------------------------------------
    | Supported:
    | - openai
    | - google
    | - deepl
    | - ollama
    */
    'translator' => env('LARAGLOT_DRIVER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Registered Models
    |--------------------------------------------------------------------------
    */
    'models' => [
        \App\Models\Page::class,
        \App\Models\Post::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Target Languages
    |--------------------------------------------------------------------------
    */
    'languages' => [
        'es' => ['name' => 'Español',   'flag' => '🇪🇸'],
        'fr' => ['name' => 'Français',  'flag' => '🇫🇷'],
        'de' => ['name' => 'Deutsch',   'flag' => '🇩🇪'],
        'ak' => ['name' => 'Twi',       'flag' => '🇬🇭'],
        'pt' => ['name' => 'Português', 'flag' => '🇧🇷'],
        'ar' => ['name' => 'العربية',   'flag' => '🇸🇦'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Structural Keys
    |--------------------------------------------------------------------------
    | Keys that should NEVER be translated.
    */
    'ignored_keys' => [
        'id',
        'uuid',
        'slug',
        'url',
        'image',
        'icon',
        'sort_order',
        'layout_type',
        'created_at',
        'updated_at',
        'color',
        'is_active',
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
    | Queue Name
    |--------------------------------------------------------------------------
    */
    'queue' => 'translations',

    /*
    |--------------------------------------------------------------------------
    | Cache Expiry
    |--------------------------------------------------------------------------
    | Default: 30 days
    */
    'cache_expiry' => 2592000,

];
```

---

# Driver Architecture

LaraGlot is driver-agnostic and supports both traditional translation engines and modern AI/LLM providers.

---

## Supported Drivers

| Driver   | Purpose                                |
| -------- | -------------------------------------- |
| `openai` | AI-powered contextual translations     |
| `google` | Fast traditional translations          |
| `deepl`  | High-quality professional translations |
| `ollama` | Local/self-hosted LLM translation      |

---

## OpenAI Driver (Recommended)

The OpenAI driver is optimized for large-scale translation workloads.

### Features

* JSON-mode batch translation
* Chunked translation pipelines
* Concurrent processing
* Retry handling
* API timeout protection
* Structured response validation

### Batch Optimization

Instead of translating one string at a time:

```php
[
    "title" => "Welcome",
    "button" => "Read More",
    "footer" => "Contact Us",
]
```

LaraGlot sends grouped translation chunks to dramatically reduce:

* API requests
* latency
* token usage
* overall cost

---

## Google / DeepL Drivers

Traditional translation APIs optimized for:

* speed
* consistency
* lower latency
* deterministic outputs

Ideal for applications needing high-volume static translations.

---

## Ollama Driver

Run translations entirely locally using self-hosted LLMs.

Perfect for:

* private deployments
* air-gapped systems
* sensitive data
* cost reduction

---

# Model Setup

LaraGlot works alongside `spatie/laravel-translatable`.

---

## 1. Add the Traits

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Tonydev\LaraGlot\Traits\HasSmartTranslations;

class Page extends Model
{
    use HasTranslations, HasSmartTranslations;

    public array $translatable = [
        'title',
        'content',
        'meta_title',
        'meta_description',
    ];

    public function getTranslatableAttributes(): array
    {
        return $this->translatable;
    }
}
```

---

## 2. Page Builder & Recursive Translation

LaraGlot automatically detects nested structures and recursively traverses them.

Example:

```php
class Page extends Model
{
    use HasTranslations, HasSmartTranslations;

    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)
            ->orderBy('sort_order');
    }
}
```

---

## What Gets Translated?

LaraGlot intelligently translates:

✅ Nested arrays
✅ JSON blocks
✅ Builder content
✅ Rich text HTML
✅ `{ "en": "..." }` language maps
✅ Repeater blocks
✅ Filament Builder sections

---

## What Gets Ignored?

Structural/system keys are automatically skipped:

```php
[
    'id',
    'slug',
    'url',
    'image',
    'layout_type',
    'sort_order',
]
```

This prevents corruption of application logic and relationships.

---

## 3. SEO Auto-Population

If a model contains a `title` but empty SEO fields:

```php
meta_title
meta_description
```

LaraGlot automatically pre-fills them from the English title before translation.

This guarantees multilingual SEO completeness even when editors forget metadata.

---

# Language File Translation

LaraGlot translates:

```php
lang/en/*.php
```

into every configured locale.

---

## Placeholder Protection

Laravel placeholders are protected before sending content to the translator.

### Input

```php
'hello' => 'Hello :name'
```

### Protected API Payload

```html
Hello <span translate="no">:name</span>
```

### Final Output

```php
'hello' => 'Bonjour :name'
```

This prevents engines from corrupting placeholders into invalid variants.

---

## Pluralization Support

Pipe-delimited plural strings are translated segment-by-segment.

### Input

```php
'items' => '{0} No items|[1,19] :count items|[20,*] :count items (bulk)'
```

### Output

```php
'items' => '{0} Aucun article|[1,19] :count articles|[20,*] :count articles (en vrac)'
```

---

## PHP Array Preservation

Files are:

* flattened via `Arr::dot`
* translated safely
* rebuilt recursively
* saved using short-array syntax

Output is written to:

```bash
lang/{locale}/{file}.php
```

Directories are created automatically if missing.

---

# Queue Setup & Timeouts

LaraGlot dispatches heavy translation work onto Laravel queues.

This keeps:

* web requests fast
* Filament responsive
* admin actions non-blocking

---

## Start a Queue Worker

```bash
php artisan queue:work --queue=translations,default
```

Recommended production command:

```bash
php artisan queue:work \
    --queue=translations,default \
    --timeout=620 \
    --tries=3
```

---

# Cascading Timeout Strategy

Because AI providers can be slow, LaraGlot uses layered timeout protection.

| Layer             | Setting                  | Default |
| ----------------- | ------------------------ | ------- |
| Queue Job         | `$timeout`               | `600s`  |
| Concurrent Worker | `Concurrency::timeout()` | `300s`  |
| HTTP Request      | `Http::timeout()`        | `120s`  |

This prevents:

* hanging workers
* zombie jobs
* stalled queue pipelines

---

## Supervisor Configuration

```ini
[program:laraglot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work redis --queue=translations,default --timeout=620 --tries=3
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/laraglot-worker.log
```

---

## Job Retry Behaviour

| Setting       | Value           |
| ------------- | --------------- |
| `$tries`      | `3`             |
| `backoff()`   | `[30, 60, 120]` |
| Model Timeout | `600s`          |
| File Timeout  | `300s`          |

If providers rate-limit requests, LaraGlot retries automatically with progressive delays.

---

# Artisan Commands

# `laraglot:sync`

Translate database models.

---

## Process All Models

```bash
php artisan laraglot:sync
```

---

## Process One Model

```bash
php artisan laraglot:sync "App\Models\Page"
```

---

## Force Re-Translation

```bash
php artisan laraglot:sync --force
```

---

## Force Specific Model

```bash
php artisan laraglot:sync "App\Models\Post" --force
```

---

## Internal Optimizations

The sync command uses:

```php
chunkById(100)
```

to safely process very large tables without memory exhaustion.

---

# `laraglot:files`

Translate PHP language files.

---

## Translate Everything

```bash
php artisan laraglot:files
```

---

## Single File

```bash
php artisan laraglot:files messages
```

---

## Single Locale

```bash
php artisan laraglot:files --locale=fr
```

---

## Force Overwrite

```bash
php artisan laraglot:files --force
```

---

## Run Synchronously

Useful for CI pipelines:

```bash
php artisan laraglot:files --sync
```

---

## Combined Example

```bash
php artisan laraglot:files messages --locale=es --force --sync
```

---

# Filament Integration

LaraGlot ships with a full Filament admin interface.

Default route:

```bash
/admin/lara-glot-manager
```

---

# LaraGlot Manager Features

## 1. Multi-File Selection

Choose one or many language files simultaneously.

---

## 2. AI Translation Preview

Synchronously translate a file and preview the output before saving.

Features:

* side-by-side diff table
* editable translations
* manual correction support
* save reviewed output

---

## 3. Queue Dispatching

Bulk-send translations to the background queue without waiting.

Perfect for:

* production deployments
* large multilingual sites
* mass content updates

---

## 4. Force Overwrite Toggle

Control whether existing translations should be replaced.

---

## 5. Sync All Files

Header action that dispatches:

```text
every file × every locale
```

combination automatically.

---

## 6. Force Re-Translate All

Rebuild your entire translation set from scratch.

Useful when:

* changing AI providers
* improving prompts
* updating source English content

---

# Resource Table Actions

Add translation buttons directly to your Filament resources.

```php
use Filament\Tables;
use Tonydev\LaraGlot\Jobs\TranslateModelJob;

public static function table(Table $table): Table
{
    return $table
        ->actions([
            Tables\Actions\Action::make('translate')
                ->label('Translate')
                ->icon('heroicon-m-language')
                ->color('warning')
                ->requiresConfirmation()
                ->action(fn ($record) => TranslateModelJob::dispatch(
                    get_class($record),
                    $record->getKey(),
                    true
                )->onQueue(config('lara-glot.queue'))),
        ])
        ->bulkActions([
            Tables\Actions\BulkAction::make('translate_selected')
                ->label('Translate Selected')
                ->icon('heroicon-m-language')
                ->requiresConfirmation()
                ->action(fn ($records) => $records->each(
                    fn ($record) => TranslateModelJob::dispatch(
                        get_class($record),
                        $record->getKey(),
                        true
                    )->onQueue(config('lara-glot.queue'))
                )),
        ]);
}
```

---

# Caching Strategy

LaraGlot uses a two-layer cache architecture to minimize API usage.

---

## Layer 1 — RAM Cache

Per-request in-memory storage:

```php
$localCache
```

If the same string appears multiple times during one translation job, LaraGlot instantly reuses the result.

---

## Layer 2 — Persistent Cache

Stored using Laravel's cache driver:

* Redis
* File
* Memcached
* Database

Cache key format:

```text
lara-glot.translation.{hash}
```

Where `{hash}` is:

```php
md5(locale + source_string)
```

---

## Cache Expiry

Default:

```php
2592000 // 30 days
```

Configurable via:

```php
cache_expiry
```

---

## Force Cache Busting

Passing:

```php
--force
```

or enabling Force Overwrite:

* clears cached entries
* forces fresh API translations

---

# Architecture

```text
LaraGlot
├── Commands
│   ├── TranslateFilesCommand
│   └── DispatchTranslations
│
├── Jobs
│   ├── TranslateFilesJob
│   └── TranslateModelJob
│
├── Drivers
│   ├── OpenAiDriver
│   ├── GoogleDriver
│   ├── DeepLDriver
│   ├── OllamaDriver
│   └── AbstractDriver
│
├── Services
│   ├── TranslationService
│   ├── FileTranslationService
│   └── SmartTranslationService
│
├── Traits
│   └── HasSmartTranslations
│
└── Filament
    └── Pages
        └── LaraGlotManager
```

---

# Service Responsibilities

## `TranslationService`

Core translation engine.

Responsibilities:

* API communication
* caching
* batching
* retries
* chunking
* timeout handling
* force refresh logic

---

## `FileTranslationService`

Handles PHP language files.

Responsibilities:

* file parsing
* `Arr::dot` flattening
* placeholder masking
* pluralization handling
* nested array rebuilding

---

## `SmartTranslationService`

Recursive model translator.

Responsibilities:

* nested JSON traversal
* Filament Builder recursion
* SEO auto-population
* change detection
* HTML chunking
* structural key skipping

---

# Logging & Monitoring

All activity is logged to:

```bash
storage/logs/laravel.log
```

---

## Log Events

| Emoji | Message                       | Meaning                         |
| ----- | ----------------------------- | ------------------------------- |
| 🚀    | Translation Job Started       | Worker picked up the job        |
| ⏭️    | Skipping Existing Translation | Translation already exists      |
| 🌍    | Translating Chunk             | Active API request              |
| ✅     | Translation Completed         | Successfully saved              |
| ⚠️    | Model Not Found               | Record deleted before execution |
| ❌     | API Error                     | Provider failure / timeout      |

---

# Failure Recovery

Failed jobs are stored in Laravel's `failed_jobs` table.

Inspect failures:

```bash
php artisan queue:failed
```

Retry one job:

```bash
php artisan queue:retry {id}
```

Retry everything:

```bash
php artisan queue:retry all
```

---

# Performance Optimizations

LaraGlot is heavily optimized for large-scale applications.

---

## Smart Chunking

Large HTML content is automatically split into manageable chunks before translation.

Prevents:

* token overflow
* API payload failures
* malformed responses

---

## Concurrent Translation

Multiple chunks can be translated simultaneously for significant speed improvements.

---

## Change Detection

LaraGlot detects whether source English content has changed before re-translating.

This avoids:

* unnecessary API usage
* duplicate translations
* wasted queue time

---

## Translation Deduplication

If the same phrase appears across:

* Pages
* Posts
* Products
* Language files

…the cached translation is reused globally.

---

# License

The MIT License (MIT).

Copyright © 2026 Tonydev.

Built with ❤️ for the Laravel community.
