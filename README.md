# LaraGlot 🌍

**Smart Auto-Translation for Laravel Models & Language Files**

LaraGlot is an industrial-strength translation pipeline for Laravel applications. It handles translating complex Eloquent models (including Filament Page Builder sections), static PHP language files, and nested JSON content — all while protecting your placeholders, HTML, and application logic from being mangled by translation engines.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Model Setup](#model-setup)
- [Language File Translation](#language-file-translation)
- [Queue Setup](#queue-setup)
- [Artisan Commands](#artisan-commands)
- [Filament Integration](#filament-integration)
- [Caching](#caching)
- [Architecture](#architecture)
- [Logging & Monitoring](#logging--monitoring)
- [License](#license)

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.1` |
| Laravel | `^10.0 \| ^11.0` |
| Filament | `^3.0` |
| spatie/laravel-translatable | `^6.0` |
| stichoza/google-translate-php | `^5.0` |

A running **queue worker** is required for background translation jobs. See [Queue Setup](#queue-setup).

---

## Installation

```bash
composer require tonydev/lara-glot
```

### Publish the Config

```bash
php artisan vendor:publish --tag=lara-glot-config
```

### Register the Commands

In your `App\Console\Kernel` (Laravel 10) or `bootstrap/app.php` (Laravel 11), ensure both commands are registered:

```php
// Laravel 10 — app/Console/Kernel.php
protected $commands = [
    \Tonydev\LaraGlot\Commands\TranslateFilesCommand::class,
    \Tonydev\LaraGlot\Commands\DispatchTranslations::class,
];
```

```php
// Laravel 11 — bootstrap/app.php
->withCommands([
    \Tonydev\LaraGlot\Commands\TranslateFilesCommand::class,
    \Tonydev\LaraGlot\Commands\DispatchTranslations::class,
])
```

---

## Configuration

After publishing, open `config/lara-glot.php`:

```php
return [

    /*
    |--------------------------------------------------------------------------
    | Source Locale
    |--------------------------------------------------------------------------
    | The locale your content is authored in. LaraGlot will never translate
    | from this locale back to itself.
    */
    'source_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Registered Models
    |--------------------------------------------------------------------------
    | Models that should be processed by the `laraglot:sync` command.
    | Each model must use the HasTranslations trait from spatie/laravel-translatable
    | and implement getTranslatableAttributes().
    */
    'models' => [
        \App\Models\Page::class,
        \App\Models\Post::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Target Languages
    |--------------------------------------------------------------------------
    | All locales LaraGlot will translate content into. The keys must be valid
    | BCP-47 language tags supported by the Google Translate API.
    */
    'languages' => [
        'es' => ['name' => 'Español',    'flag' => '🇪🇸'],
        'fr' => ['name' => 'Français',   'flag' => '🇫🇷'],
        'de' => ['name' => 'Deutsch',    'flag' => '🇩🇪'],
        'ak' => ['name' => 'Twi',        'flag' => '🇬🇭'],
        'pt' => ['name' => 'Português',  'flag' => '🇧🇷'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Language Files
    |--------------------------------------------------------------------------
    | PHP files inside lang/en/ that should NOT be translated. Typically these
    | are Laravel's own framework files which ship with official translations.
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
    | The queue that TranslateModelJob and TranslateFilesJob will be dispatched
    | on. Run a dedicated worker for this queue for best performance.
    */
    'queue' => 'translations',

    /*
    |--------------------------------------------------------------------------
    | Cache Expiry (seconds)
    |--------------------------------------------------------------------------
    | How long translated strings are cached in your application cache driver.
    | Default: 2,592,000 seconds = 30 days.
    */
    'cache_expiry' => 2592000,

];
```

---

## Model Setup

### 1. Add the Trait

LaraGlot works alongside `spatie/laravel-translatable`. Add both traits to any model you want auto-translated:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Tonydev\LaraGlot\Traits\HasSmartTranslations;

class Page extends Model
{
    use HasTranslations, HasSmartTranslations;

    /**
     * Attributes stored as translatable JSON by spatie/laravel-translatable.
     */
    public array $translatable = [
        'title',
        'content',
        'meta_title',
        'meta_description',
    ];

    /**
     * Required by LaraGlot — returns the list of attributes to translate.
     */
    public function getTranslatableAttributes(): array
    {
        return $this->translatable;
    }
}
```

### 2. Models with Sections (Page Builder Support)

If your model has a `sections` relationship (e.g. a Filament page builder), LaraGlot will automatically recurse into each section's `content` JSON and translate any `{ "en": "..." }` style translation maps it finds:

```php
class Page extends Model
{
    use HasTranslations, HasSmartTranslations;

    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('sort_order');
    }
}
```

The `SmartTranslationService` will skip non-translatable keys automatically (`id`, `slug`, `url`, `image`, `icon`, `sort_order`, `layout_type`, and similar structural keys).

### 3. Automatic SEO Population

When a model has a `title` attribute, LaraGlot will automatically populate empty `meta_title` and `meta_description` fields from the English title before translation — ensuring SEO fields are never blank.

---

## Language File Translation

LaraGlot translates your `lang/en/*.php` files into all configured locales, preserving placeholder syntax and pluralization:

### Placeholder Protection

Laravel placeholders (`:name`, `:count`, `:attribute`) are wrapped in `<span translate="no">` before being sent to the translation API, then cleanly restored afterwards. This prevents translations like `"Bonjour :nom"` breaking your application.

### Pluralization Support

Pipe-delimited plural strings are processed segment by segment:

```php
// Input
'items' => '{0} No items|[1,19] :count items|[20,*] :count items (bulk)',

// Output (French)
'items' => '{0} Aucun article|[1,19] :count articles|[20,*] :count articles (en vrac)',
```

### Saving Output

Translated files are written using short PHP array syntax and saved to `lang/{locale}/{filename}.php`, creating the directory if it does not exist.

---

## Queue Setup

LaraGlot dispatches all heavy translation work to the queue so your web requests and UI stay responsive.

### Start a Worker

```bash
# Recommended: dedicated translations queue with fallback to default
php artisan queue:work --queue=translations,default

# With supervisor-friendly options (timeout matches job timeout of 600s)
php artisan queue:work --queue=translations,default --timeout=620 --tries=3
```

### Supervisor Configuration (Production)

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

### Job Behaviour

Both `TranslateModelJob` and `TranslateFilesJob` share the same resilience configuration:

| Setting | Value |
|---|---|
| `$tries` | `3` |
| `$timeout` | `600s` (models) / `300s` (files) |
| `backoff()` | `[30, 60, 120]` seconds |

If the translation API is rate-limiting, jobs will automatically retry with increasing delays before being marked as failed.

---

## Artisan Commands

### `laraglot:files` — Translate PHP Language Files

Dispatches `TranslateFilesJob` for every file × locale combination.

```bash
# Dispatch jobs for all files and all configured locales
php artisan laraglot:files

# Translate only a specific file
php artisan laraglot:files messages

# Target a single locale only
php artisan laraglot:files --locale=fr

# Overwrite files that already exist
php artisan laraglot:files --force

# Run synchronously (no queue — useful for CI pipelines)
php artisan laraglot:files --sync

# Combine flags
php artisan laraglot:files messages --locale=es --force --sync
```

### `laraglot:sync` — Translate Database Models

Scans registered models and dispatches `TranslateModelJob` for each record that needs translation.

```bash
# Process all models listed in config/lara-glot.php
php artisan laraglot:sync

# Process a specific model only
php artisan laraglot:sync "App\Models\Page"

# Force re-translation of all records (ignores existing translations)
php artisan laraglot:sync --force

# Combine model + force
php artisan laraglot:sync "App\Models\Post" --force
```

The sync command uses chunked queries (`chunkById(100)`) and a progress bar to handle large tables safely without exhausting memory.

---

## Filament Integration

### Admin Page — LaraGlot Manager

LaraGlot ships with a full Filament admin page at `/admin/lara-glot-manager`.

**Features:**
- **Multi-file select** — choose one or many source files at once
- **AI Preview** — synchronously translate a single file and display a diff table you can edit before saving
- **Dispatch Jobs** — send selected files to the background queue without waiting
- **Force Overwrite toggle** — control whether existing translations are replaced
- **Sync All Files** (header action) — dispatch one job per file × locale for every configured combination
- **Force Re-translate All** (header action) — same as above but overwrites all existing files

### Table Actions on Your Resources

Add per-record and bulk translation triggers to any Filament resource:

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
                    true // force
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

## Caching

LaraGlot uses a two-layer cache to keep API costs low and response times fast:

### Layer 1 — RAM Cache (per request)

Every translated string is stored in an in-memory array (`$localCache`) for the duration of the current PHP process. If the same string is encountered again in the same job or request, it is returned instantly with zero overhead.

### Layer 2 — Persistent Cache (Redis / File)

Results are stored in your configured Laravel cache driver under the key `lara-glot.translation.{hash}` where `{hash}` is an MD5 of the locale + source string. The default TTL is 30 days (configurable via `cache_expiry`).

### Forcing a Cache Bust

Pass `force: true` to any command, job, or the Filament Force Overwrite toggle. This calls `Cache::forget()` on the relevant key before re-translating, ensuring fresh results from the API.

---

## Architecture

```
LaraGlot
├── Commands
│   ├── TranslateFilesCommand     laraglot:files  — dispatches TranslateFilesJob
│   └── DispatchTranslations      laraglot:sync   — dispatches TranslateModelJob
│
├── Jobs
│   ├── TranslateFilesJob         Translates a single PHP file to one locale
│   └── TranslateModelJob         Translates all translatable attributes on one model
│
├── Services
│   ├── TranslationService        Core API wrapper — multi-layer caching, rate-limit protection
│   ├── FileTranslationService    PHP file parsing, Arr::dot flattening, placeholder masking
│   └── SmartTranslationService   Recursive model/section translation, SEO auto-population
│
├── Traits
│   └── HasSmartTranslations      Eloquent model trait — hooks into saved events
│
└── Filament
    └── Pages
        └── LaraGlotManager       Full admin UI — preview, edit, dispatch, sync
```

### Service Responsibilities

**`TranslationService`**
The single point of contact with the Google Translate API. Handles multi-layer caching, empty-string skipping, and force-refresh logic. Used by both `FileTranslationService` and `SmartTranslationService`.

**`FileTranslationService`**
Loads a PHP language file, flattens it with `Arr::dot`, masks placeholders, sends the flat array to `TranslationService::translateBatch()`, restores placeholders, and writes the result back to a nested PHP array file.

**`SmartTranslationService`**
The model translation engine. Recurses into translatable attributes, handles page-builder section JSON, auto-populates SEO fields, detects whether English content has changed since the last translation, and chunks large HTML blocks to avoid API limits.

---

## Logging & Monitoring

All activity is written to `storage/logs/laravel.log`:

| Emoji | Message | Meaning |
|---|---|---|
| 🚀 | `[LaraGlot] Translation Job Started` | Job was picked up by a worker |
| ⏭️ | `[LaraGlot] Skipping (already exists)` | File exists and force is false |
| 🌍 | `[LaraGlot] Translating [locale]: ...` | Live API call in progress |
| ✅ | `[LaraGlot] Translation completed` | File or model saved successfully |
| ⚠️ | `[LaraGlot] Model not found` | Record was deleted before job ran |
| ❌ | `[LaraGlot] API Error [locale]: ...` | API call failed — job will retry |

Failed jobs after all retries are exhausted are written to the `failed_jobs` table. Inspect them with:

```bash
php artisan queue:failed
php artisan queue:retry {id}
php artisan queue:retry all
```

---

## License

The MIT License (MIT). Copyright © Tonydev. Developed with ❤️ for the Laravel community.
