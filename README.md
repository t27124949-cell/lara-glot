# LaraGlot

Automatic translation for Laravel. LaraGlot translates Eloquent models with JSON translatable attributes, nested page-builder content, and static PHP language files — and it goes to great lengths to never pay for the same translation twice.

Five drivers ship out of the box: Anthropic Claude, OpenAI, DeepL, Google Translate, and Ollama for local models. Laravel placeholders, URLs, HTML tags, and your brand terms survive translation intact.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Drivers](#drivers)
- [Keeping Costs Down](#keeping-costs-down)
- [Glossary and Brand Terms](#glossary-and-brand-terms)
- [Quality Review Pass](#quality-review-pass)
- [Placeholder Protection](#placeholder-protection)
- [Model Setup](#model-setup)
- [Language File Translation](#language-file-translation)
- [Artisan Commands](#artisan-commands)
- [Queue Setup](#queue-setup)
- [Filament Integration](#filament-integration)
- [Custom Drivers](#custom-drivers)
- [Custom Prompts](#custom-prompts)
- [Failure Behaviour](#failure-behaviour)
- [Upgrading from 1.x](#upgrading-from-1x)
- [License](#license)

## Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.3` |
| Laravel | `^11.0 \| ^12.0 \| ^13.0` |
| spatie/laravel-translatable | `^6.0` (for model translation) |
| filament/filament | `^3.0` (for the admin UI) |

## Installation

```bash
composer require tonydev/lara-glot
```

The service provider is auto-discovered. Then publish the config:

```bash
php artisan vendor:publish --tag=lara-glot-config
```

Two drivers need an extra package; the rest use Laravel's HTTP client directly:

```bash
# Google (unofficial endpoint, no API key required)
composer require stichoza/google-translate-php:^5.3

# Ollama (local models)
composer require cloudstudio/ollama-laravel:^2.0
```

If you use queued model translation, make sure job batching is migrated:

```bash
php artisan queue:batches-table
php artisan migrate
```

## Configuration

Pick a driver and set its key in `.env`:

```env
LARAGLOT_DRIVER=anthropic
ANTHROPIC_API_KEY=sk-ant-...

# or
LARAGLOT_DRIVER=openai
OPENAI_API_KEY=sk-...

# or
LARAGLOT_DRIVER=deepl
DEEPL_API_KEY=...
```

Every driver accepts the same tuning knobs in `config/lara-glot.php`: chunk size, retries, retry delay, concurrency, and cache TTL. The defaults are sensible; you rarely need to touch them.

## Drivers

| Driver | Needs a key | Best for |
|---|---|---|
| `anthropic` | yes | Best translation quality. Defaults to `claude-haiku-4-5` (fast, cheap); set `claude-sonnet-5` when nuance matters most. |
| `openai` | yes | Works with any OpenAI-compatible endpoint — OpenAI, Azure, Groq, Together AI. Defaults to `gpt-5-mini`. |
| `deepl` | yes | Strong European-language quality at a flat per-character price. Free-tier keys (ending `:fx`) are detected automatically. |
| `google` | no | Prototyping and low-volume work. Uses the unofficial web endpoint — no SLA, so don't build production on it. |
| `ollama` | no | Fully local and free. Point it at any model your hardware can run. Defaults to `llama3.2`. |

To use another OpenAI-compatible provider, change the base URL — no code changes:

```env
# Groq
LARAGLOT_OPENAI_BASE_URL=https://api.groq.com/openai/v1
LARAGLOT_OPENAI_MODEL=llama-3.3-70b-versatile

# Azure OpenAI
LARAGLOT_OPENAI_BASE_URL=https://your-deployment.openai.azure.com/v1
```

## Keeping Costs Down

This is where LaraGlot earns its keep. A translation API call is made only when a string has genuinely never been translated before:

1. **In-process cache.** Within one request or job, a repeated string costs a RAM lookup, nothing more.
2. **Persistent cache.** Every translation is stored in your Laravel cache (30 days by default), keyed per string, per language pair, per driver. Re-running a command, re-saving a model, or rebuilding language files re-uses everything already translated.
3. **In-batch deduplication.** If "Save" appears forty times across your language files, it is sent to the API once and fanned back out to all forty keys.
4. **Change detection.** Model translation tracks a hash of the source content and skips fields that haven't changed since the last run.
5. **Per-string caching, not per-chunk.** Editing one string in a file invalidates that string only — the other forty-nine stay cached.

In practice a second full run over an unchanged project costs zero API calls. You can verify this yourself: every driver exposes `getStats()` with cache hits, misses, and the actual API call count.

## Glossary and Brand Terms

Two tools to keep terminology under control:

```php
'glossary' => [
    // Never translated — byte-identical in every language.
    // Works with all five drivers.
    'protected_terms' => ['LaraGlot', 'Acme Cloud'],

    // Forced translations per target locale.
    // Applied by the LLM drivers (anthropic, openai, ollama).
    'terms' => [
        'checkout' => ['de' => 'Kasse', 'fr' => 'paiement'],
    ],
],
```

`protected_terms` are shielded with the same token mechanism used for URLs, so even Google and DeepL can't mangle them. Matching is case-sensitive and whole-word: `LaraGlot` is protected, `laraglots` is not.

Changing the glossary automatically invalidates the affected cached translations — you never serve stale wording after a terminology decision.

## Quality Review Pass

Machine translation is accurate but often stiff. With the review pass enabled, each freshly translated chunk gets a second call where the model acts as a native-speaker reviewer: it fixes overly literal phrasing, replaces vocabulary no real product would use, and returns already-good translations unchanged.

```env
LARAGLOT_REVIEW=true
```

Cost notes, because they matter:

- The review only runs on strings that weren't cached — and the **reviewed** result is what gets cached. You pay the extra call once per string, ever.
- If the review call fails for any reason, the first draft is kept. A translation is never lost to the reviewer.
- LLM drivers only; Google and DeepL ignore the flag.

## Placeholder Protection

Translation engines will happily turn `:count` into `:nombre` and break your app. Before any text leaves your server, LaraGlot replaces sensitive tokens with opaque markers, and swaps them back afterwards:

```
:name                   → __BRACE_0__
https://example.com     → __URL_1__
<span translate="no">…  → __HTML_2__
LaraGlot (glossary)     → __TERM_3__
```

Input:

```
"Welcome back, :name! Visit https://example.com for details."
```

Sent to the API:

```
"Welcome back, __BRACE_0__! Visit __URL_1__ for details."
```

After restore:

```
"Bienvenue, :name ! Visitez https://example.com pour plus de détails."
```

This is a hard guarantee enforced in code, not a prompt instruction, and it applies to every driver and every translation path.

## Model Setup

Add the trait to any model using `spatie/laravel-translatable`:

```php
use Spatie\Translatable\HasTranslations;
use Tonydev\LaraGlot\Traits\HasSmartTranslations;

class Page extends Model
{
    use HasTranslations;
    use HasSmartTranslations;

    public $translatable = ['title', 'body', 'seo_title', 'seo_description'];
}
```

Register it in `config/lara-glot.php`:

```php
'models' => [
    \App\Models\Page::class,
],
```

Nested JSON content (page-builder sections, repeaters) is translated recursively. Keys listed in `ignored_keys` — identifiers, media paths, ordering fields — are passed through untouched. The published config ships with a minimal generic list (`id`, `uuid`, `slug`, `url`, `image`, `icon`, `color`, `sort_order`, `external_id`); add your own app-specific keys there.

## Language File Translation

Translates your `lang/en/*.php` files into every configured locale, preserving nested key structure:

```bash
# Everything, queued
php artisan laraglot:files

# One file, one locale, synchronously
php artisan laraglot:files auth --locale=fr --sync

# Overwrite existing translation files
php artisan laraglot:files --force
```

Files listed in `exclude_files` (framework files like `validation`) are skipped.

## Artisan Commands

### `laraglot:sync`

Scans registered models and dispatches translation jobs for anything new or changed.

```bash
php artisan laraglot:sync                          # all registered models
php artisan laraglot:sync "App\Models\Page"        # one model
php artisan laraglot:sync --locale=fr --locale=de  # limit target locales
php artisan laraglot:sync --force                  # ignore change detection
```

### `laraglot:files`

Translates PHP language files. See the section above for options (`file?`, `--locale=`, `--force`, `--sync`).

## Queue Setup

Heavy work runs on Laravel queues (`translations` by default):

```bash
php artisan queue:work --queue=translations,default --timeout=620 --tries=4
```

For production, run the worker under Supervisor with `--timeout` above the model job timeout (600s).

## Filament Integration

Register the plugin in your panel provider:

```php
use Tonydev\LaraGlot\LaraGlotPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(LaraGlotPlugin::make());
}
```

You get a management page showing translation coverage per model and language, with actions to queue missing or forced re-translations.

## Custom Drivers

Register your own engine without forking the package. In a service provider's `boot()`:

```php
use Tonydev\LaraGlot\Services\TranslationService;

TranslationService::extend('my-engine', fn () => new MyEngineDriver());
```

Then set `LARAGLOT_DRIVER=my-engine`. The driver needs to implement `Tonydev\LaraGlot\Contracts\TranslationDriverInterface` — two methods, `translate()` and `translateBatch()`. Extend `AbstractLlmDriver` instead if your engine is prompt-based and you want chunking, caching, deduplication, glossary support, and the review pass for free; then the only method to write is `sendChunk()`.

## Custom Prompts

The built-in LLM prompt asks for translations that read like a native speaker wrote them, matching the register of the source text. If you want full control:

```php
'prompt' => 'Translate the JSON array from {source} to {target}. ... your rules ...',
```

The string replaces the built-in prompt entirely; `{source}` and `{target}` are substituted. Changing it invalidates the affected cache entries, same as glossary changes.

## Failure Behaviour

The rule everywhere: a failed translation returns the original string, never an exception and never a half-translated token soup.

- Chunks retry with progressive back-off (3 attempts by default), then fall back to the originals.
- Placeholders are restored even on the fallback path — callers never see `__VAR_0__`.
- Review-pass failures keep the first draft.
- Everything is logged under the `[LaraGlot:Driver]` tag, so failures are visible without being fatal.

## Upgrading from 1.x

- **`ignored_keys` defaults shrank** to a generic list. If you relied on removed defaults (`cta_url`, `is_active`, and similar app-specific keys), add them to your published config.
- **Ollama caching changed** from chunk-level to string-level. Old chunk cache entries are simply ignored; strings re-cache individually on the next run.
- **New config blocks** (`glossary`, `review`, `prompt`, `drivers.anthropic`) are optional — re-publish the config or copy them in if you want the new features.
- Model defaults moved to current generations (`gpt-5-mini`, `llama3.2`). Pin your previous model via env if you need to stay put.

## License

MIT.
