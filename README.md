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
- [Upgrading to 2.1](#upgrading-to-21)
- [Upgrading from 1.x](#upgrading-from-1x)
- [License](#license)

## Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.3` |
| Laravel | `^11.0 \| ^12.0 \| ^13.0` |
| spatie/laravel-translatable | `^6.0` (for model translation) |
| filament/filament | `^4.0 \| ^5.0` (for the admin UI) |

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

Running `php artisan migrate` also creates the `lara_glot_translation_retries` table, which tracks values that fell back to source text so `laraglot:retry` can re-attempt them. It's optional — without it, translation still works and LaraGlot logs a single notice instead of tracking fallbacks.

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

Every driver accepts the same tuning knobs in `config/lara-glot.php`: chunk size, retries, retry delay, concurrency, HTTP timeout, and cache TTL. The defaults are sensible; you rarely need to touch them.

For slow reasoning models (e.g. DeepSeek-class models behind an OpenAI-compatible endpoint), raise the timeout and/or lower the chunk size:

```env
LARAGLOT_OPENAI_TIMEOUT=180
LARAGLOT_OPENAI_CHUNK_SIZE=10
```

When a chunk still times out (or gets rate-limited), the LLM drivers automatically retry it in smaller sub-chunks before falling back, so one slow request no longer drops a whole chunk of strings to source text.

### Fallback detection

When a driver fails on a string, that string falls back to the source text. LaraGlot refuses to let that go unnoticed:

- `laraglot:files --sync` prints a per-file `translated / identical-to-source` count, warns when the fallback share exceeds `lara-glot.fallback.warn_ratio` (default `0.5`), and exits non-zero on failures.
- When *every* translatable string in a file comes back identical to the source — the signature of a failed run — the file is **not written** and the job fails so it can retry, instead of an untranslated English file masquerading as done. Disable via `LARAGLOT_FAIL_ON_FULL_FALLBACK=false` if a locale is legitimately identical to the source.

### Protected values

Values that are references rather than prose — `'link' => 'route:contact'`, bare URLs, `mailto:`/`tel:` links — are passed through untranslated. Patterns are configurable in `lara-glot.protected_value_patterns`.

## Drivers

| Driver | Needs a key | Best for |
|---|---|---|
| `anthropic` | yes | Best translation quality. Defaults to `claude-haiku-4-5` (fast, cheap); set `claude-sonnet-5` when nuance matters most. |
| `openai` | yes | Works with any OpenAI-compatible endpoint — OpenAI, Azure, Groq, Together AI. Defaults to `gpt-5-mini`. |
| `deepl` | yes | Strong European-language quality at a flat per-character price. Free-tier keys (ending `:fx`) are detected automatically. |
| `google` | no | Prototyping and low-volume work. Uses the unofficial web endpoint — no SLA, so don't build production on it. Long strings are split on sentence boundaries automatically (`LARAGLOT_GOOGLE_MAX_LENGTH`, default 1500 chars) since the free endpoint 500s on long payloads, and every response is verified to have preserved placeholder tokens before being accepted. |
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

Nested JSON content (page-builder sections, repeaters) is translated recursively. Keys listed in `ignored_keys` — identifiers, security values, media paths, locale/format config, geo and ordering fields, timestamps — are passed through untouched. The published config ships with a comprehensive list of keys that are never human-readable labels (`id`, `uuid`, `slug`, `sku`, `token`, `avatar`, `latitude`, `created_at`, …); add your own app-specific keys there. Deliberately absent are words like `email` or `price` that are often translatable labels in language files.

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

### `laraglot:audit`

Reports every language-file key and model attribute whose value is byte-identical to the source locale — the signature of a silent translation fallback — and can repair them in place:

```bash
php artisan laraglot:audit                        # audit all files + registered models
php artisan laraglot:audit --locale=ar --locale=de
php artisan laraglot:audit --files-only --file=footer
php artisan laraglot:audit --models-only "--model=App\Models\Faq"
php artisan laraglot:audit --repair               # re-translate ONLY the identical values
php artisan laraglot:audit --threshold=0.5        # flag at ≥50% identical (default 0.85)
```

- Exits non-zero when any file/model locale is flagged at or above the threshold, so it works as a CI gate or scheduled canary (`Schedule::command('laraglot:audit')`).
- `--repair` re-translates only the values still equal to the source (missing keys included) with a forced cache refresh, so fixing fallbacks never re-bills the whole corpus and evicts poisoned cache entries.
- Values that are *supposed* to be identical across locales belong in `lara-glot.audit.allowlist` — they're never flagged or re-billed. The default list ships with common acronyms, units, and platform names ("OK", "SMS", "PDF", "iOS", "PayPal", …); extend it in the published config. `ignored_keys` and `protected_value_patterns` are excluded automatically.

### `laraglot:retry`

Whenever a value falls back to source text during a translation run, LaraGlot records it in the `lara_glot_translation_retries` table — a fallback is never silent. This command re-attempts those units:

```bash
php artisan laraglot:retry               # process everything currently due
php artisan laraglot:retry --limit=100
```

- **Bounded, with back-off:** each failed attempt reschedules the unit at `base_delay_minutes × 2^attempts` (default 30 min base); after `max_attempts` (default 5) the unit is parked as *exhausted* and the command exits non-zero so it surfaces for human review instead of re-billing forever. Tune via `lara-glot.retry`.
- **Never wastes an API call:** units whose source text changed, whose key was removed, or that were already healed elsewhere (an audit `--repair`, a `--force` run, a manual edit) are resolved without touching the driver.
- **Idempotent + resumable:** run it on a schedule (`Schedule::command('laraglot:retry')->hourly()`) and coverage converges; exhausted strings that are legitimately identical belong in `lara-glot.audit.allowlist`.

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

Two rules, depending on where you are:

**At render time** (a page reading a translation), failure is graceful: a failed translation returns the original string, never an exception and never half-translated token soup. Placeholders are restored even on the fallback path — users never see `__VAR_0__`.

**In jobs and commands**, failure is loud — a batch that writes English into `lang/ar/` while reporting success is corruption, not resilience:

- Chunks retry with exponential back-off; transient failures (timeouts, 429s, 5xx) additionally retry in halved sub-chunks before any string falls back.
- A batch driver error **throws** — the job fails visibly and retries, and nothing gets cached or written.
- A file whose output is 100% identical to the source is treated as a failed run: not written, job failed.
- Any individual string that does fall back is **recorded** in the retry table and re-attempted by `laraglot:retry` — a fallback is acceptable, a *silent* fallback is not.
- Corrupt cache entries read as misses and are evicted automatically; forced refreshes (`--force`, `--repair`, retries) bypass every cache layer, including the driver's own.
- Everything is logged under the `[LaraGlot:Driver]` tag.

## Upgrading to 2.1

2.1 fixes a data-corrupting bug and tightens failure behaviour. Read this if you ran 2.0 in production:

- **Critical fix — multibyte corruption:** under Laravel's default `process` concurrency driver, translations to Arabic, Chinese, Hindi, and other multibyte scripts were silently replaced with the English source (`unserialize(): Error at offset …` in the log was the only trace). Fixed for both file and model translation. **Audit your existing locales** — `php artisan laraglot:audit` reports the damage and `--repair` heals only the affected values.
- **Filament v4/v5 only:** the admin page uses the Schemas API and a non-static `$view`; Filament 3 panels are no longer supported.
- **Run `php artisan migrate`:** creates the optional `lara_glot_translation_retries` table used by `laraglot:retry`.
- **Behaviour change:** batch translation failures in jobs/commands now throw instead of silently writing source text; a 100% source-identical file fails the job instead of being written. Escape hatch: `LARAGLOT_FAIL_ON_FULL_FALLBACK=false`.
- **`ignored_keys` defaults grew** substantially (identifiers, media paths, timestamps, …). If any of the new defaults collide with translatable label keys in your app, remove them in your published config.
- **New config blocks** — `retry`, `audit` (threshold + allowlist), `fallback`, `protected_value_patterns`, per-driver `timeout`, `drivers.google.max_length` — all optional with sensible defaults; re-publish the config to pick them up.

## Upgrading from 1.x

- **`ignored_keys` defaults shrank** to a generic list. If you relied on removed defaults (`cta_url`, `is_active`, and similar app-specific keys), add them to your published config.
- **Ollama caching changed** from chunk-level to string-level. Old chunk cache entries are simply ignored; strings re-cache individually on the next run.
- **New config blocks** (`glossary`, `review`, `prompt`, `drivers.anthropic`) are optional — re-publish the config or copy them in if you want the new features.
- Model defaults moved to current generations (`gpt-5-mini`, `llama3.2`). Pin your previous model via env if you need to stay put.

## License

MIT.
Created by TONY THE DEVELOPER