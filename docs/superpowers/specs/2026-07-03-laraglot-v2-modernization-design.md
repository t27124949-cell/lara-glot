# LaraGlot v2 Modernization — Design

**Date:** 2026-07-03
**Status:** Approved
**Scope:** Modernize the package, add an Anthropic Claude driver, glossary/term locking, and an opt-in quality-review pass. Improve translation naturalness and clean up AI-sounding docs.

## Goals

1. Remove outdated defaults and app-specific leftovers from a public package.
2. Add a native Anthropic Claude driver.
3. Add glossary support: protected (never-translated) terms and forced per-locale translations.
4. Add an opt-in second-pass quality review for LLM drivers.
5. Rewrite LLM prompts so output reads like a native speaker wrote it, not machine translation.
6. Rewrite README and code comments to read human and professional.
7. Keep all 36 existing tests green; add tests for every new behavior.

## Non-Goals

- DeepL's native glossary API (prompt-based glossary only; note as future work).
- Translation-memory database.
- Filament UI redesign (only expose the new driver option).
- Changing the public API of `TranslationService`, jobs, or commands.

## Architecture

### 1. Shared LLM layer

Extract the LLM-shaped logic currently in `OpenAiDriver` into:

```
AbstractTranslationDriver            (existing — cache, retry, concurrency, stats)
└── AbstractLlmDriver                (new — chunked JSON translation protocol)
    ├── OpenAiDriver                 (thin: HTTP call to /chat/completions)
    ├── AnthropicDriver              (new, thin: HTTP call to /v1/messages)
    └── OllamaDriver                 (thin: call via ollama-laravel or HTTP)
```

`AbstractLlmDriver` owns:

- `translateBatch()`: cache-first flow, chunk split, concurrent fan-out, cache write-back, key-order restore (moved from `OpenAiDriver` unchanged).
- `translateChunk()`: placeholder protection, glossary term protection, JSON encode, retry loop, response count validation, placeholder restore, fallback-to-originals.
- `buildSystemPrompt()`: shared prompt including glossary directives; overridable via config.
- Optional review pass (see §4).

Concrete drivers implement:

- `sendChunk(string $jsonInput, string $systemPrompt): string` — one raw API round-trip returning the model's text output.
- Constructor config bootstrap and `driverName()`.

`GoogleDriver` and `DeepLDriver` stay direct subclasses of `AbstractTranslationDriver` (they are not prompt-based).

### 2. AnthropicDriver

- Endpoint: `POST {base_url}/v1/messages` with `x-api-key` and `anthropic-version` headers.
- System prompt goes in the top-level `system` field; the JSON payload is the single user message.
- Default model: `claude-haiku-4-5` (verify exact current ID against the claude-api reference at implementation time). Config allows `claude-sonnet-5` for maximum nuance.
- Config block mirrors the OpenAI driver: `api_key` (env `ANTHROPIC_API_KEY`), `model`, `base_url`, `chunk_size`, `max_retries`, `retry_delay_ms`, `concurrency`, `cache_enabled`, `cache_ttl`, plus `max_tokens` (required by the Messages API).
- Registered as `'anthropic'` in the driver map.

### 3. Glossary & term locking

New config block:

```php
'glossary' => [
    // Byte-identical in every language (brand names, product names).
    'protected_terms' => [],

    // Forced translations per target locale. LLM drivers only.
    'terms' => [
        // 'checkout' => ['de' => 'Kasse', 'fr' => 'paiement'],
    ],
],
```

- **`protected_terms`** are tokenized in `ProtectsPlaceholders` (same mechanism as URLs → `__TERM_N__`), so they survive **every** driver including Google and DeepL. Matching is case-sensitive whole-word.
- **`terms`** are injected into the LLM system prompt as explicit directives ("Translate 'checkout' as 'Kasse'."). Only terms with an entry for the current target locale are injected. Non-LLM drivers ignore `terms`; the README documents this limitation.
- Glossary content is part of the cache key salt: changing the glossary must invalidate stale cached translations. Implementation: append a short hash of the glossary config to `getCacheKey()` input for LLM drivers.

### 4. Quality-review pass

New config block:

```php
'review' => [
    'enabled' => env('LARAGLOT_REVIEW', false),
],
```

- Applies to `AbstractLlmDriver` subclasses only.
- After a chunk translates successfully, a second API call sends source/draft pairs and asks the model to return a corrected JSON array of the same length. Reviewer instructions: fix unnatural or overly literal phrasing, remove AI-generic vocabulary, match the register of the source, leave protected tokens untouched, return a translation unchanged when it is already good.
- Review failures (HTTP error, bad JSON, wrong count) log a warning and keep the first draft — review must never lose a translation.
- The **post-review** result is what gets cached, so review cost is paid once per string.
- Review uses the same driver/model as the translation call (keeping it simple; per-review driver selection is future work).

### 5. Prompt overhaul

Rewrite the shared system prompt around naturalness:

- Translate meaning, not words; output must read as if originally written in the target language.
- Match the register and tone of the source (UI label stays terse; marketing copy stays warm).
- Use vocabulary a native speaker would actually use in a real product.
- Keep all existing structural rules: JSON-array-only output, exact element count/order, never touch `__…_N__` tokens or `:placeholders`, preserve HTML tags.
- Glossary directives (§3) appended when applicable.

Config gains `'prompt' => null` — when set to a string, it fully replaces the built-in system prompt (with `{source}`/`{target}` placeholders substituted).

### 6. Polish & modernization

- **Model defaults:** OpenAI `gpt-4o-mini` → current mini-tier model; Ollama `llama3` → current default (e.g. `llama3.3` or newer); verify at implementation time. DeepL/Google API usage verified current.
- **`ignored_keys`:** reduce to generic defaults only: `id`, `uuid`, `slug`, `url`, `image`, `icon`, `color`, `sort_order`, `external_id`. App-specific keys (`is_crypto`, `is_deposit_enabled`, `cta_url`, …) removed — users add their own.
- **Driver extensibility:** `TranslationService::extend(string $name, Closure $factory)` static registry checked before the built-in map, so apps register custom drivers without forking.
- **Docs:** rewrite README in plain professional English (no emoji headers, no "industrial-strength"); thin decorative comment banners in source down to purposeful comments.
- **composer.json:** description/keywords updated to mention Anthropic/Claude.

## Error handling

Unchanged philosophy, extended to new code: every failure path degrades to returning the original string, never throws to the caller, and logs with the `[LaraGlot:Driver]` tag. Review-pass failures degrade to the unreviewed draft. Missing `ANTHROPIC_API_KEY` fails fast with a clear log message on first use, falling back to originals like other drivers.

## Testing

- All 36 existing tests stay green (the `AbstractLlmDriver` extraction must be behavior-preserving).
- New unit tests: `AnthropicDriver` (request shape, response parsing, fallback), glossary protection (`protected_terms` tokenization round-trip, prompt injection of `terms`, cache-key salting), review pass (applies corrections, falls back on failure, caches post-review result), `TranslationService::extend()`.
- HTTP is faked with `Http::fake()` per existing test conventions.

## Rollout

Single release (v2.0.0). Breaking changes: trimmed `ignored_keys` defaults (users relying on removed defaults must add them to their published config) — called out in README upgrade notes.
