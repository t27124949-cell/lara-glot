<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Tonydev\LaraGlot\Drivers\Concerns\DecodesJsonResponse;
use Tonydev\LaraGlot\Drivers\Concerns\ProtectsPlaceholders;

/**
 * Base class for all LaraGlot translation drivers.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * What this class provides (so drivers don't repeat themselves):
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *  Config properties     — $maxRetries, $concurrencyLimit, $cacheEnabled, $cacheTtl
 *                          Declared here so every constructor just assigns them.
 *
 *  Cache helpers         — getFromCache(), putInCache(), getCacheKey(), isCached()
 *                          All cache I/O goes through these so counters stay accurate.
 *
 *  Concurrency helper    — runConcurrentBatches()
 *                          Chunks an array of tasks and fans them out with Laravel
 *                          Concurrency::run(), preserving original array keys.
 *
 *  Retry helper          — withRetry()
 *                          Progressive back-off; shared across all drivers.
 *
 *  Normalisation         — normalizeTranslated()
 *                          Strips whitespace + decodes HTML entities on every result.
 *
 *  Stats tracking        — getStats(), resetStats()
 *                          In-memory hit/miss/API-call counters for the current run.
 *
 *  Public utilities      — warmUp(), setCacheEnabled(), isCached()
 *                          Extra API surface that makes the package worth buying.
 *
 *  Log tag               — logTag()
 *                          "[LaraGlot:Google]" / "[LaraGlot:Ollama]" etc. in one place.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * What concrete drivers must implement:
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *  driverName(): string          Short snake_case name → "google", "ollama", "deepl"
 *                                Used in cache key prefixes and log tags.
 *
 *  translateBatch(): array       Core translation logic for a batch of strings.
 *
 * Drivers may also override translate() when a cheaper single-string path exists
 * (see GoogleDriver for an example).
 */
abstract class AbstractTranslationDriver implements TranslationDriverInterface
{
      use ProtectsPlaceholders;
      use DecodesJsonResponse;

      // ─────────────────────────────────────────────────────────────────────────
      // Shared config properties
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * How many times to retry a failed API call before giving up.
       * Each concrete driver constructor should overwrite this from its own
       * config key so operators can tune per-driver.
       */
      protected int $maxRetries = 3;

      /**
       * Base delay in milliseconds between retry attempts.
       * The actual delay is progressive: $retryDelayMs × attempt number.
       *   attempt 1 fail → wait  1× retryDelayMs
       *   attempt 2 fail → wait  2× retryDelayMs
       * Each driver sets this from its own config key (retry_delay_ms) so
       * operators can tune per-driver — local LLMs need longer delays than
       * cloud APIs with reliable uptime.
       */
      protected int $retryDelayMs = 500;

      /**
       * Maximum number of parallel tasks per Concurrency::run() call.
       * Keeping this low protects against rate-limiting; raise it for drivers
       * that have higher quotas (e.g. a self-hosted Ollama instance).
       */
      protected int $concurrencyLimit = 5;

      /**
       * Whether to read/write the Laravel cache for translation results.
       * Disable in tests or when you explicitly need a fresh call to the API.
       */
      protected bool $cacheEnabled = true;

      /**
       * How long (in seconds) a cached translation remains valid.
       * Default: 2 592 000 s = 30 days — matches the global LARAGLOT_CACHE_EXPIRY default.
       */
      protected int $cacheTtl = 2_592_000;

      // ─────────────────────────────────────────────────────────────────────────
      // Runtime stats (per request / CLI invocation)
      // ─────────────────────────────────────────────────────────────────────────

      /** How many cache hits occurred since the last resetStats(). */
      private int $cacheHits = 0;

      /** How many cache misses occurred since the last resetStats(). */
      private int $cacheMisses = 0;

      /** How many actual outbound API calls were made since the last resetStats(). */
      private int $apiCalls = 0;

      // ─────────────────────────────────────────────────────────────────────────
      // Abstract contract
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Return a short, lowercase name that identifies this driver.
       *
       * Used for:
       *  • Cache key prefixes  →  "laraglot:google:en:fr:..."
       *  • Log tags            →  "[LaraGlot:Google] ..."
       *
       * Examples: 'google', 'ollama', 'deepl', 'openai'
       */
      abstract protected function driverName(): string;

      /**
       * Translate a batch of strings from $source to $target.
       *
       * Keys in the returned array MUST match the keys of $texts exactly.
       * Values must be translated strings (or the original string on failure).
       *
       * @param  array<int|string, string> $texts
       * @return array<int|string, string>
       */
      abstract public function translateBatch(
            array $texts,
            string $target,
            string $source = 'en'
      ): array;

      // ─────────────────────────────────────────────────────────────────────────
      // Default translate() — delegates to translateBatch()
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate a single string.
       *
       * The default implementation simply wraps translateBatch() so drivers that
       * only implement batch translation get single-string support for free.
       *
       * Override this (as GoogleDriver does) when you have a cheaper, more direct
       * code path for single strings — e.g. to avoid JSON-array overhead.
       */
      public function translate(string $text, string $target, string $source = 'en'): string
      {
            if (trim($text) === '') {
                  return $text;
            }

            $results = $this->translateBatch([$text], $target, $source);

            return $results[0] ?? $text;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Public utility methods
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Pre-translate and cache a list of strings without returning them to the UI.
       *
       * Designed for Artisan warm-up commands or queue jobs that run before users
       * visit a new locale. Because translateBatch() already writes to cache on
       * success, simply calling it is sufficient.
       *
       * @param  array<int|string, string> $texts
       * @return array<int|string, string>  The translated map (useful for logging).
       */
      public function warmUp(array $texts, string $target, string $source = 'en'): array
      {
            return $this->translateBatch($texts, $target, $source);
      }

      /**
       * Toggle the cache on or off at runtime.
       *
       * Fluent so callers can chain:
       *   app(GoogleDriver::class)->setCacheEnabled(false)->translate(...)
       *
       * Useful in:
       *  - Feature tests that need fresh API responses.
       *  - Admin tooling that forces a re-translation.
       */
      public function setCacheEnabled(bool $enabled): static
      {
            $this->cacheEnabled = $enabled;

            return $this;
      }

      /**
       * Check whether a specific string is already cached for the given locale pair.
       *
       * Does NOT count as a cache hit in stats because it makes no guarantee about
       * what the caller will do next (they might still call translate() anyway).
       *
       * Returns false when caching is disabled entirely.
       */
      public function isCached(string $text, string $target, string $source = 'en'): bool
      {
            if (!$this->cacheEnabled) {
                  return false;
            }

            return Cache::has($this->getCacheKey($text, $target, $source));
      }

      /**
       * Return a snapshot of in-memory stats for the current request or CLI run.
       *
       * Useful for:
       *  - Smoke-testing ("did the warm-up actually hit the cache?")
       *  - Performance logging in queue jobs.
       *  - Debug output in Artisan commands.
       *
       * @return array{cache_hits: int, cache_misses: int, api_calls: int, hit_rate: float}
       */
      public function getStats(): array
      {
            $total = $this->cacheHits + $this->cacheMisses;

            return [
                  'cache_hits' => $this->cacheHits,
                  'cache_misses' => $this->cacheMisses,
                  'api_calls' => $this->apiCalls,

                  // hit_rate: 0.0–1.0 fraction of cache hits out of total lookups.
                  // Returns 0.0 when no lookups have occurred yet.
                  'hit_rate' => $total > 0
                        ? round($this->cacheHits / $total, 4)
                        : 0.0,
            ];
      }

      /**
       * Zero out the runtime counters.
       *
       * Call this between test cases, or at the start of each Artisan command
       * invocation, to get clean per-run stats.
       */
      public function resetStats(): void
      {
            $this->cacheHits = 0;
            $this->cacheMisses = 0;
            $this->apiCalls = 0;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Cache helpers  (all cache I/O goes through these methods)
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Build the cache key for a single translation string.
       *
       * Default format:
       *   laraglot:{driver}:{source}:{target}:{sha256(text)}
       *
       * SHA-256 is used (rather than md5) because collision resistance matters
       * when keys are stored persistently and text can be user-supplied.
       *
       * Drivers that cache at a different granularity (e.g. whole chunks)
       * should define an additional protected method alongside this one
       * rather than overriding it — so that isCached() and the single-string
       * helpers keep working correctly.
       */
      protected function getCacheKey(string $text, string $target, string $source): string
      {
            return sprintf(
                  'laraglot:%s:%s:%s:%s',
                  $this->driverName(),
                  $source,
                  $target,
                  hash('sha256', $text)
            );
      }

      /**
       * Attempt to retrieve a cached translation string.
       *
       * Returns the cached value on a hit, or null on a miss / disabled cache.
       * Automatically increments the hit or miss counter.
       */
      protected function getFromCache(string $key): ?string
      {
            // Short-circuit when caching is disabled so callers don't need to check.
            if (!$this->cacheEnabled) {
                  return null;
            }

            $value = Cache::get($key);

            if (is_string($value)) {
                  // Track the hit so getStats() reflects real cache behaviour.
                  $this->cacheHits++;

                  return $value;
            }

            $this->cacheMisses++;

            return null;
      }

      /**
       * Persist a translated string in the cache.
       *
       * No-op when caching is disabled, so callers never need an if-guard.
       */
      protected function putInCache(string $key, string $value): void
      {
            if ($this->cacheEnabled) {
                  Cache::put($key, $value, $this->cacheTtl);
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Concurrency helper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Fan out an array of callables using Laravel's Concurrency::run(), processing
       * them in sequential batches of $batchSize tasks each.
       *
       * HOW CONCURRENCY IS CONTROLLED:
       * Concurrency::run() accepts only one argument — the tasks array — and runs
       * every task in that array in parallel. There is no second argument for a
       * limit. $batchSize therefore IS the concurrency control:
       *
       *   $batchSize = 3, 9 tasks  →  3 parallel rounds of 3
       *   $batchSize = 9, 9 tasks  →  1 parallel round of 9  (maximum throughput)
       *   $batchSize = 1, 9 tasks  →  9 sequential tasks      (no parallelism)
       *
       * Each batch blocks until all its tasks complete before the next batch starts.
       * Set $batchSize to $this->concurrencyLimit in each driver's translateBatch().
       *
       * WHY NOT CALL Concurrency::run() DIRECTLY ON ALL TASKS?
       *  1. Memory / rate-limit safety — fanning out hundreds of tasks at once can
       *     exhaust process memory or trigger API 429s. Batching keeps pressure predictable.
       *  2. Key preservation — this wrapper re-merges results under their original
       *     array keys, which Concurrency::run() does not guarantee on its own.
       *
       * @param  array<string|int, callable> $tasks      Map of original key → callable.
       * @param  int                         $batchSize  Tasks per Concurrency::run() call.
       *                                                 Pass $this->concurrencyLimit here.
       * @return array<string|int, mixed>                Results keyed identically to $tasks.
       */
      protected function runConcurrentBatches(array $tasks, int $batchSize): array
      {
            $results = [];

            foreach (array_chunk($tasks, $batchSize, true) as $batch) {
                  // We use the 'process' driver explicitly to access the timeout configuration.
                  // By default, this is 60s. We increase it to 300s (5 minutes) to 
                  // allow slow LLMs or large batches to finish.
                  $batchResults = Concurrency::driver('process')
                        ->timeout(300)
                        ->run($batch);

                  foreach ($batchResults as $key => $value) {
                        $results[$key] = $value;
                  }
            }

            return $results;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Retry helper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Execute $fn up to $maxAttempts times with progressive back-off.
       *
       * Back-off schedule (with $baseDelayMs = 500):
       *   attempt 1 fails → wait   500 ms
       *   attempt 2 fails → wait 1 000 ms
       *   attempt 3 fails → re-throw
       *
       * The last exception is always re-thrown so callers can decide how to
       * handle total failure (log + fallback vs. bubble up to the user).
       *
       * @template T
       * @param  callable(): T $fn           The operation to attempt.
       * @param  int           $maxAttempts  Total attempts (≥ 1).
       * @param  int           $baseDelayMs  Base delay in milliseconds.
       * @param  string        $context      Short label appended to the log tag,
       *                                     e.g. ':Google' → "[LaraGlot:Google]".
       * @return T
       *
       * @throws \Throwable  The last exception after all attempts are exhausted.
       */
      protected function withRetry(
            callable $fn,
            int $maxAttempts = 3,
            int $baseDelayMs = 500,
            string $context = ''
      ): mixed {
            $lastException = null;

            // Build the log tag once — avoids repeated string interpolation in the loop.
            $tag = $context !== ''
                  ? "[LaraGlot{$context}]"
                  : '[LaraGlot]';

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                  try {
                        return $fn();
                  } catch (\Throwable $e) {
                        $lastException = $e;

                        Log::warning("{$tag} Attempt {$attempt}/{$maxAttempts} failed.", [
                              'error' => $e->getMessage(),
                        ]);

                        // Only sleep when there are more attempts remaining.
                        if ($attempt < $maxAttempts) {
                              // Progressive back-off: 500 ms, 1 000 ms, 1 500 ms, …
                              usleep($baseDelayMs * $attempt * 1_000);
                        }
                  }
            }

            // All attempts exhausted — re-throw so the caller can decide what to do.
            throw $lastException;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Normalisation helper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Post-process every translated string before it leaves the driver.
       *
       * Steps:
       *  1. Guard against non-string API responses (return '' for null / arrays).
       *  2. Decode HTML entities that some APIs encode in their output
       *     (e.g. Google encodes "&amp;" → "&", "&#39;" → "'").
       *  3. Strip leading/trailing whitespace.
       *
       * Call this on every value before putting it in the cache or returning
       * it to the caller.
       *
       * @param  mixed $value  Raw value from the translation API / model.
       * @return string        Clean, ready-to-use string.
       */
      protected function normalizeTranslated(mixed $value): string
      {
            if (!is_string($value)) {
                  return '';
            }

            return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Log tag helper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Return the consistent log prefix for this driver.
       *
       * Example: "[LaraGlot:Google]" for GoogleDriver, "[LaraGlot:Ollama]" for OllamaDriver.
       *
       * Using a single helper guarantees every log line is grep-able by driver name
       * without each driver hardcoding its own string.
       */
      protected function logTag(): string
      {
            return '[LaraGlot:' . ucfirst($this->driverName()) . ']';
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Internal counter helper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Increment the API-call counter by one.
       *
       * Drivers must call this once per actual outbound network request so that
       * getStats() accurately reflects how many API calls were made vs. how many
       * were served from cache.
       */
      protected function recordApiCall(): void
      {
            $this->apiCalls++;
      }
}
