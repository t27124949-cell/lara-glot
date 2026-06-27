# LaraGlot 🌍

**Smart Auto-Translation for Laravel Models, Language Files & Nested Content**

LaraGlot is an industrial-strength translation pipeline for Laravel applications. It intelligently translates:

- Eloquent models with JSON translatable attributes
- Nested JSON / Page Builder section content
- Static PHP language files
- SEO metadata
- Rich HTML content

…while protecting Laravel placeholders, URLs, HTML tags, IDs, slugs, and structural data from being corrupted by translation engines or LLMs.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Driver Architecture](#driver-architecture)
- [Model Setup](#model-setup)
- [Language File Translation](#language-file-translation)
- [Placeholder Protection](#placeholder-protection)
- [Queue Setup](#queue-setup)
- [Artisan Commands](#artisan-commands)
- [Filament Integration](#filament-integration)
- [Caching Strategy](#caching-strategy)
- [Architecture Overview](#architecture-overview)
- [Service Responsibilities](#service-responsibilities)
- [Logging & Monitoring](#logging--monitoring)
- [Failure Recovery](#failure-recovery)
- [License](#license)

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.3` |
| Laravel | `^11.0 \| ^12.0 \| ^13.0` |
| spatie/laravel-translatable | `^6.0` *(for model translation)* |
| filament/filament | `^3.0` *(for admin UI)* |

> **Laravel 13** (released March 2026) is fully supported. PHP 8.3 is the minimum — the package uses `readonly` properties, `array_is_list()`, `match()`, and named arguments throughout.

---

## Installation

```bash
composer require tonydev/lara-glot
```

The service provider is auto-discovered via Laravel's package discovery. No manual registration needed.

---

### Install Driver Dependencies

Only install the package for the driver you intend to use:

```bash
# Google (unofficial, no API key required)
composer require stichoza/google-translate-php:^5.3

# Ollama (local LLM)
composer require cloudstudio/ollama-laravel:^2.0
```

DeepL and OpenAI use Laravel's built-in HTTP client — no extra packages needed.

---

### Publish the Config

```bash
php artisan vendor:publish --tag=lara-glot-config
```

### Publish Views (optional — for Filament UI customisation)

```bash
php artisan vendor:publish --tag=lara-glot-views
```

### Ensure Job Batching is Set Up

LaraGlot uses Laravel's job batching. If you haven't already:

```bash
php artisan queue:batches-table
php artisan migrate
```

---

## Configuration

After publishing, edit `config/lara-glot.php`:

```php
return [

    'source_locale' => env('LARAGLOT_SOURCE_LOCALE', 'en'),

    'translator' => env('LARAGLOT_DRIVER', 'google'),

    'models' => [
        \App\Models\Page::class,
        \App\Models\Post::class,
    ],

    'languages' => [
        'es' => ['name' => 'Español',   'flag' => '🇪🇸'],
        'fr' => ['name' => 'Français',  'flag' => '🇫🇷'],
        'de' => ['name' => 'Deutsch',   'flag' => '🇩🇪'],
        'ar' => ['name' => 'العربية',   'flag' => '🇸🇦'],
    ],

    'ignored_keys' => [
        'id', 'uuid', 'slug', 'url', 'image', 'icon',
        'sort_order', 'layout_type', 'color', 'is_active',
    ],

    'exclude_files' => [
        'auth', 'pagination', 'passwords', 'validation',
    ],

    'queue'        => env('LARAGLOT_QUEUE', 'translations'),
    'cache_expiry' => env('LARAGLOT_CACHE_EXPIRY', 2592000), // 30 days
];
```

---

### Environment Variables

```dotenv
# Driver selection
LARAGLOT_DRIVER=google          # google | openai | deepl | ollama
LARAGLOT_SOURCE_LOCALE=en

# OpenAI
OPENAI_API_KEY=sk-...
LARAGLOT_OPENAI_MODEL=gpt-4o-mini
LARAGLOT_OPENAI_BASE_URL=https://api.openai.com/v1   # swap for Groq, Together AI, etc.

# DeepL
DEEPL_API_KEY=your-deepl-key    # keys ending in :fx use the free endpoint automatically

# Ollama
LARAGLOT_OLLAMA_MODEL=llama3

# Cache
LARAGLOT_CACHE_EXPIRY=2592000
```

---

## Driver Architecture

LaraGlot ships four drivers. Set `LARAGLOT_DRIVER` to switch between them with no code changes.

| Driver | Best For | Requires |
|---|---|---|
| `google` | Development, low-volume | `stichoza/google-translate-php` |
| `deepl` | High-quality professional output | `DEEPL_API_KEY` |
| `openai` | Contextual AI translation | `OPENAI_API_KEY` |
| `ollama` | Private / air-gapped deployments | `cloudstudio/ollama-laravel` |

All drivers share the same architecture via `AbstractTranslationDriver`:

- **Placeholder protection** — `:name`, URLs, `<span translate="no">` are replaced with opaque tokens before any API call and restored after
- **Retry with back-off** — progressive delays between attempts
- **Per-driver caching** — results cached individually at the string level
- **Concurrency** — chunks fanned out in parallel via `Concurrency::run()`
- **Graceful fallback** — permanent failure returns the original string, never throws to the caller

---

### OpenAI-Compatible Providers

The `openai` driver works with any provider that exposes the `/chat/completions` endpoint:

```dotenv
# Groq
LARAGLOT_OPENAI_BASE_URL=https://api.groq.com/openai/v1

# Together AI
LARAGLOT_OPENAI_BASE_URL=https://api.together.xyz/v1

# Azure OpenAI
LARAGLOT_OPENAI_BASE_URL=https://your-resource.openai.azure.com/openai/deployments/your-deployment
```

---

### DeepL Free vs Pro

Free-tier DeepL keys end with `:fx`. LaraGlot detects this automatically and routes to `api-free.deepl.com` — no configuration needed.

---

## Model Setup

LaraGlot works alongside `spatie/laravel-translatable`.

### 1. Add the Trait

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Page extends Model
{
    use HasTranslations;

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

### 2. Register the Model

Add the model to `config/lara-glot.php`:

```php
'models' => [
    \App\Models\Page::class,
],
```

---

### 3. Page Builder & Nested Section Support

If your model has a `sections` relationship with nested JSON content, LaraGlot traverses it automatically:

```php
class Page extends Model
{
    use HasTranslations;

    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('sort_order');
    }
}
```

LaraGlot detects `{ "en": "..." }` maps inside nested arrays and translates them recursively, skipping any key listed in `ignored_keys`.

---

### 4. SEO Auto-Population

If a model has a `title` attribute but empty `meta_title` / `meta_description` fields, LaraGlot fills them from the English title before translation begins — ensuring multilingual SEO completeness automatically.

---

### 5. Change Detection

LaraGlot stores the last-translated English value in `_en_original`. On subsequent runs it compares the current English value against the stored one — only re-translating when the source has actually changed. Use `--force` to bypass this.

---

## Language File Translation

LaraGlot translates `lang/en/*.php` into every configured locale.

Files are:

1. Loaded with `File::getRequire()`
2. Flattened via `Arr::dot()`
3. Keys in `ignored_keys` are passed through unchanged
4. Remaining strings sent to the driver in batches
5. Result rebuilt into nested array structure
6. Written to `lang/{locale}/{file}.php` using clean short-array syntax

Directories are created automatically if missing.

---

## Placeholder Protection

All four drivers protect dynamic tokens before sending any content to a translation API. This is the reliable guarantee — prompts alone are not sufficient.

**Three token types are protected:**

```
:name, :count          → __VAR_0__, __VAR_1__
https://example.com    → __URL_2__
<span translate="no">  → __HTML_3__
```

**Example — input:**
```
"Welcome back, :name! Visit https://example.com for details."
```

**Sent to API:**
```
"Welcome back, __VAR_0__! Visit __URL_1__ for details."
```

**After translation + restore:**
```
"Bienvenue, :name ! Visitez https://example.com pour plus de détails."
```

This applies to all drivers and all translation paths (model attributes, language files, section content).

---

## Queue Setup

LaraGlot dispatches all heavy work onto Laravel queues.

### Start a Worker

```bash
php artisan queue:work --queue=translations,default
```

### Recommended Production Command

```bash
php artisan queue:work \
    --queue=translations,default \
    --timeout=620 \
    --tries=4
```

---

### Timeout Strategy

| Layer | Setting | Value |
|---|---|---|
| Model job | `$timeout` | `600s` |
| File job | `$timeout` | `300s` |
| HTTP request (DeepL/OpenAI) | `Http::timeout()` | `60–90s` |

---

### Job Retry Behaviour

| Job | `$tries` | `backoff()` |
|---|---|---|
| `TranslateModelJob` | `4` | `[30, 60, 120]` |
| `TranslateFilesJob` | `4` | `[30, 60, 120]` |
| `TranslateFilePreviewJob` | `3` | `[30, 60]` |

---

### Supervisor Configuration

```ini
[program:laraglot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work redis --queue=translations,default --timeout=620 --tries=4
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/laraglot-worker.log
```

---

## Artisan Commands

### `laraglot:sync`

Translate registered Eloquent model records via the queue.

```bash
# All registered models
php artisan laraglot:sync

# One specific model
php artisan laraglot:sync "App\Models\Page"

# Force re-translation (ignore change detection)
php artisan laraglot:sync --force

# Force a specific model
php artisan laraglot:sync "App\Models\Post" --force
```

Uses `chunkById(100)` internally — safe for tables with millions of rows.

---

### `laraglot:files`

Translate PHP language files.

```bash
# All files, all locales
php artisan laraglot:files

# One specific file
php artisan laraglot:files messages

# One specific locale
php artisan laraglot:files --locale=fr

# Force overwrite existing files
php artisan laraglot:files --force

# Run synchronously (useful for CI pipelines)
php artisan laraglot:files --sync

# Combined
php artisan laraglot:files messages --locale=es --force --sync
```

---

## Filament Integration

### 1. Register the Plugin

In your Filament `PanelProvider`:

```php
use Tonydev\LaraGlot\LaraGlotPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugins([
            LaraGlotPlugin::make(),
        ]);
}
```

The admin page is available at `/admin/lara-glot-manager`.

---

### 2. LaraGlot Manager Features

| Feature | Description |
|---|---|
| File selector | Choose one or many language files |
| Language selector | Choose one or many target locales |
| AI Preview | Translate a file and review/edit output before saving |
| Dispatch Jobs | Send translations to the background queue |
| Force Toggle | Overwrite existing translations |
| Sync All Files | Dispatch every file × every locale in one click |
| Force Re-translate All | Rebuild entire translation set |
| Model translation | Select models and locales to translate |
| Batch progress | Live progress bar with polling |
| Cancel batch | Stop a running batch mid-flight |

Batch progress survives page refreshes — the batch ID is persisted to the session and restored on mount.

---

### 3. Resource Table Actions

Add per-record translation buttons to any Filament resource:

```php
use Tonydev\LaraGlot\Jobs\TranslateModelJob;

Tables\Actions\Action::make('translate')
    ->label('Translate')
    ->icon('heroicon-m-language')
    ->color('warning')
    ->requiresConfirmation()
    ->action(fn ($record) => dispatch(
        new TranslateModelJob(
            get_class($record),
            $record->getKey(),
            force: true,
            locales: []  // empty = all configured locales
        )
    )->onQueue(config('lara-glot.queue'))),
```

---

## Caching Strategy

LaraGlot uses a two-layer cache to minimise API usage.

### Layer 1 — In-Process RAM Cache

A `$localCache` array on `TranslationService` stores results for the current request or job. If the same string appears multiple times in one run, the API is called only once.

Capped at 1,000 entries — oldest half is pruned when the limit is reached to keep memory bounded.

### Layer 2 — Laravel Persistent Cache

All results are stored in your configured Laravel cache driver (Redis, file, database, etc.).

**Cache key format:**

```
lara-glot.translation.{md5(source|target|text)}
```

The source locale is included in the hash — `fr → de` and `en → de` for the same string produce different keys.

**Driver-level cache** also stores results under a separate key:

```
laraglot:{driver}:{source}:{target}:{sha256(text)}
```

**Default TTL:** 30 days (`2,592,000` seconds), configurable via `LARAGLOT_CACHE_EXPIRY`.

### Force Cache Busting

Pass `--force` to any command or enable the Force toggle in the Filament UI to evict both cache layers and force fresh API translations.

---

## Architecture Overview

```
LaraGlot
├── Commands
│   ├── TranslateFilesCommand       laraglot:files
│   └── DispatchTranslations        laraglot:sync
│
├── Jobs
│   ├── TranslateFilesJob           One file × one locale
│   ├── TranslateModelJob           One model record
│   └── TranslateFilePreviewJob     Preview job (cache-based result)
│
├── Drivers
│   ├── AbstractTranslationDriver   Shared: cache, retry, concurrency, normalisation
│   ├── GoogleDriver                Unofficial Google Translate endpoint
│   ├── DeepLDriver                 DeepL REST API
│   ├── OpenAiDriver                OpenAI-compatible chat completions
│   └── OllamaDriver                Local LLM via cloudstudio/ollama
│
├── Drivers/Concerns
│   ├── ProtectsPlaceholders        :name / URL / <span> token protect & restore
│   └── DecodesJsonResponse         Robust LLM JSON parsing (fences, BOM, envelopes)
│
├── Services
│   ├── TranslationService          Driver resolver, two-layer cache, batch coordinator
│   ├── FileTranslationService      File I/O, Arr::dot, ignored_keys filtering
│   ├── SmartTranslationService     Recursive model/section translation, SEO population
│   └── ModelTranslationManager     Batch dispatch, progress tracking, cancellation
│
├── Contracts
│   └── TranslationDriverInterface  translate() + translateBatch()
│
├── LaraGlotPlugin                  Filament plugin (make() factory)
├── LaraGlotServiceProvider         Container bindings, publishes, commands
│
└── Filament
    └── Pages
        └── LaraGlotManager         Admin UI — files, models, preview, batch progress
```

---

## Service Responsibilities

### `TranslationService`

The central translation engine. Wraps the active driver with:

- In-process RAM cache (LRU-pruned at 1,000 entries)
- Persistent Laravel cache with configurable TTL
- `$force` cache busting
- Source locale included in cache key hash
- Graceful fallback to original string on failure

Everything else calls into this service — never the driver directly.

---

### `FileTranslationService`

Handles PHP language file translation:

- Loads files with `File::getRequire()`
- Flattens with `Arr::dot()`
- Filters `ignored_keys` by checking the last dot-notation segment of each key
- Delegates batches to `TranslationService`
- Rebuilds nested structure and writes clean short-array PHP syntax

---

### `SmartTranslationService`

Recursive model attribute translator:

- Acquires a distributed lock per record (`Cache::lock`) to prevent concurrent double-translation
- Calls `getTranslatableAttributes()` / `getTranslations()` / `setTranslations()` from Spatie
- Recursively traverses nested JSON / Page Builder sections
- Detects `{ "en": "..." }` maps and translates each locale independently
- Skips keys in `ignored_keys`
- Auto-populates `meta_title` / `meta_description` from `title` when empty
- Detects English source changes via `_en_original` sentinel key
- Uses `Concurrency::run()` with correct key-preservation for parallel locale processing
- Splits strings over 2,000 characters into chunks before translation

---

### `ModelTranslationManager`

Batch orchestration layer:

- `translateSync()` — blocking, for use in the current request
- `translateAsync()` — single queued job with optional delay
- `translateBatch()` — batch of model instances
- `translateModelClass()` — all records of one class, chunked with `chunkById()`
- `translateModelClasses()` — all records across multiple classes in one batch
- `getBatchStatus()` — progress snapshot for Filament polling
- `cancelBatch()` — cancels a running batch

All batch methods accept a `$locales` array — empty means all configured locales.

---

## Logging & Monitoring

All activity is logged to `storage/logs/laravel.log`.

| Emoji | Meaning |
|---|---|
| 🔍 | Translation started for a model |
| 🚀 | Batch or job dispatched |
| ⏭️ | Skipped — already translated, source unchanged |
| ✅ | Translation completed and saved |
| ⏸️ | Skipped — distributed lock held by another process |
| ⚠️ | Model record not found (deleted between dispatch and processing) |
| ❌ | API error or permanent failure |

---

## Failure Recovery

Failed jobs are stored in Laravel's `failed_jobs` table.

```bash
# View failed jobs
php artisan queue:failed

# Retry one job
php artisan queue:retry {id}

# Retry all
php artisan queue:retry all
```

All drivers return the **original string** on permanent failure — the application never receives an exception from a translation call. The failure is logged and the job is recorded in `failed_jobs` for later retry.

---

## License

The MIT License (MIT).

Copyright © 2026 Tonydev.

Built with ❤️ for the Laravel community.