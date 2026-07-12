<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Throwable;

/**
 * Google Translate driver (unofficial, via stichoza/google-translate-php).
 *
 * The endpoint is undocumented and has no SLA, so this driver is built for
 * graceful degradation: it never throws to the caller, adds optional jitter
 * between requests to avoid rate limits, and uses a fresh client per call so
 * concurrent tasks share no state. Good for development and low-volume work;
 * use DeepL or an LLM driver for production traffic.
 *
 * Config (lara-glot.drivers.google.*): max_retries, retry_delay_ms,
 * concurrency, batch_delay_ms, cache_enabled, cache_ttl.
 */
class GoogleDriver extends AbstractTranslationDriver
{
      /**
       * Optional per-task delay (microseconds × 1000) injected before each
       * concurrent translation to spread load and reduce the risk of a 429.
       * 0 = no delay.
       */
      protected int $batchDelayMs;

      /**
       * Maximum characters per request. The free endpoint 500s intermittently
       * on long multi-sentence GET payloads; strings above this length are
       * split on sentence boundaries, translated part by part, and rejoined.
       */
      protected int $maxLength;

      // ─────────────────────────────────────────────────────────────────────────
      // Bootstrap
      // ─────────────────────────────────────────────────────────────────────────

      public function __construct()
      {
            $this->maxRetries = (int) config('lara-glot.drivers.google.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.google.retry_delay_ms', 300);
            $this->concurrencyLimit = max(1, (int) config('lara-glot.drivers.google.concurrency', 5));
            $this->cacheEnabled = (bool) config('lara-glot.drivers.google.cache_enabled', true);
            $this->cacheTtl = (int) config('lara-glot.drivers.google.cache_ttl', 2_592_000);
            $this->batchDelayMs = (int) config('lara-glot.drivers.google.batch_delay_ms', 0);
            $this->maxLength = max(200, (int) config('lara-glot.drivers.google.max_length', 1500));
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Driver identity
      // ─────────────────────────────────────────────────────────────────────────

      protected function driverName(): string
      {
            return 'google';
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Single-string translation  (overrides the default batch-delegate)
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate a single string from $source to $target.
       *
       * WHY OVERRIDE?
       * Google's unofficial API translates one string at a time. There is no
       * batch endpoint, so there is no JSON-array overhead to avoid. A direct
       * call is cheaper than packaging the text into a one-element array and
       * unwrapping the result as the default implementation would do.
       *
       * FLOW:
       *  1. Return empty string immediately for blank input.
       *  2. Check the cache — return the cached value if present.
       *  3. Protect placeholders (`:name`, URLs, no-translate spans).
       *  4. Call the unofficial Google endpoint with retry/back-off. Strings
       *     longer than max_length are split on sentence boundaries and
       *     translated part by part — the free endpoint 500s on long GETs.
       *     Each attempt verifies the opaque tokens survived the round-trip;
       *     a mangled token counts as a failed attempt and is retried.
       *  5. Restore placeholders + normalize whitespace/entities.
       *  6. Write to cache, return to caller.
       *  7. On any failure: log the error and return the original string
       *     (graceful fallback — this driver never throws to the caller).
       */
      public function translate(
            string $text,
            string $target,
            string $source = 'en'
      ): string {
            // ── Guard: nothing to translate ───────────────────────────────────────
            if (trim($text) === '') {
                  return $text;
            }

            // ── Cache read ────────────────────────────────────────────────────────
            $cacheKey = $this->getCacheKey($text, $target, $source);
            $cached = $this->getFromCache($cacheKey);

            if ($cached !== null) {
                  return $cached;
            }

            // ── Protect dynamic tokens before sending to the API ──────────────────
            [$protected, $placeholders] = $this->protectPlaceholders($text);

            try {
                  // ── API call(s) — long strings go sentence-by-sentence ────────────
                  if (mb_strlen($protected) > $this->maxLength) {
                        $parts = [];

                        foreach ($this->splitLongText($protected, $this->maxLength) as $segment) {
                              $parts[] = $this->callGoogle($segment, $target, $source, $placeholders);
                        }

                        $translated = implode(' ', $parts);
                  } else {
                        $translated = $this->callGoogle($protected, $target, $source, $placeholders);
                  }

                  // ── Post-processing ───────────────────────────────────────────────
                  $final = $this->restorePlaceholders(
                        $this->normalizeTranslated($translated),
                        $placeholders
                  );

                  // ── Cache write ───────────────────────────────────────────────────
                  $this->putInCache($cacheKey, $final);

                  return $final;

            } catch (Throwable $e) {
                  Log::error("{$this->logTag()} Translation failed.", [
                        'target' => $target,
                        'source' => $source,
                        'text' => mb_substr($text, 0, 100),
                        'error' => $e->getMessage(),
                  ]);

                  return $text;
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Single API round-trip with retry + token verification
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * One retried call to the unofficial endpoint. Verifies that every
       * placeholder token present in THIS piece of text survived the
       * round-trip byte-identical — the engine sometimes translates or
       * re-cases tokens like __BRACE_0__, which would leak raw tokens into
       * the final string. A mangled token is treated as a failed attempt.
       *
       * @param  array<string,string> $placeholders  Full map for the original string;
       *                                             filtered to the tokens in $text.
       */
      protected function callGoogle(
            string $text,
            string $target,
            string $source,
            array $placeholders
      ): string {
            // Long strings are split into segments, each carrying only a
            // subset of the tokens — verify just the ones actually sent.
            $expected = array_filter(
                  $placeholders,
                  static fn($original, $key) => str_contains($text, $key),
                  ARRAY_FILTER_USE_BOTH
            );

            // recordApiCall() is INSIDE the closure so every HTTP attempt —
            // including retries — increments the counter accurately.
            return $this->withRetry(
                  function () use ($text, $source, $target, $expected): string {
                        // Count every actual outbound HTTP attempt, not just the first.
                        $this->recordApiCall();

                        // New instance per call = no shared state between concurrent tasks.
                        $client = new GoogleTranslate();

                        $result = $client
                              ->setSource($source)
                              ->setTarget($target)
                              ->translate($text);

                        if (!is_string($result) || trim($result) === '') {
                              throw new \RuntimeException(
                                    'Google Translate returned an empty result.'
                              );
                        }

                        if (!$this->placeholdersSurvived($result, $expected)) {
                              throw new \RuntimeException(
                                    'Google Translate mangled a placeholder token.'
                              );
                        }

                        return $result;
                  },
                  $this->maxRetries,
                  $this->retryDelayMs,
                  ':Google'
            );
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Long-string splitting
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Split a long string into segments of at most $maxLength characters,
       * cutting on sentence boundaries where possible and falling back to
       * word boundaries for a single overlong sentence. Placeholder tokens
       * contain no whitespace, so they are never cut in half.
       *
       * @return list<string>
       */
      protected function splitLongText(string $text, int $maxLength): array
      {
            $sentences = preg_split(
                  '/(?<=[.!?…。！？؟])\s+/u',
                  $text,
                  -1,
                  PREG_SPLIT_NO_EMPTY
            ) ?: [$text];

            // Break any single sentence that still exceeds the cap on words.
            $pieces = [];

            foreach ($sentences as $sentence) {
                  if (mb_strlen($sentence) <= $maxLength) {
                        $pieces[] = $sentence;
                        continue;
                  }

                  $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [$sentence];
                  $current = '';

                  foreach ($words as $word) {
                        $candidate = $current === '' ? $word : "{$current} {$word}";

                        if (mb_strlen($candidate) > $maxLength && $current !== '') {
                              $pieces[] = $current;
                              $current = $word;
                        } else {
                              $current = $candidate;
                        }
                  }

                  if ($current !== '') {
                        $pieces[] = $current;
                  }
            }

            // Greedily pack pieces back together up to the cap so we make as
            // few requests as possible.
            $segments = [];
            $current = '';

            foreach ($pieces as $piece) {
                  $candidate = $current === '' ? $piece : "{$current} {$piece}";

                  if (mb_strlen($candidate) > $maxLength && $current !== '') {
                        $segments[] = $current;
                        $current = $piece;
                  } else {
                        $current = $candidate;
                  }
            }

            if ($current !== '') {
                  $segments[] = $current;
            }

            return $segments;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch translation
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate multiple strings concurrently.
       *
       * FLOW:
       *  1. Skip blank / non-string values immediately (preserve as-is).
       *  2. Serve cache hits without building a task.
       *  3. Wrap each remaining string in a closure for Concurrency::run().
       *     Optionally inject a jitter delay to spread load.
       *  4. Fan out all tasks via runConcurrentBatches() (inherited helper).
       *  5. Merge results and restore original key order with ksort().
       *
       * NOTE: translateBatch() pre-checks the cache before creating a task,
       * and translate() re-checks inside the task. The second check is a fast
       * in-memory lookup and is kept intentionally — it guards against a cache
       * write that may have occurred between the two checks in a concurrent run.
       *
       * @param  array<int|string, string> $texts
       * @return array<int|string, string>
       */
      public function translateBatch(
            array $texts,
            string $target,
            string $source = 'en'
      ): array {
            $results = [];
            $tasks = [];

            foreach ($texts as $key => $text) {
                  // ── Skip non-strings and blank values ─────────────────────────────
                  if (!is_string($text) || trim($text) === '') {
                        $results[$key] = $text;
                        continue;
                  }

                  // ── Cache shortcut ────────────────────────────────────────────────
                  $cached = $this->getFromCache($this->getCacheKey($text, $target, $source));

                  if ($cached !== null) {
                        $results[$key] = $cached;
                        continue;
                  }

                  // ── Enqueue as a concurrent task ──────────────────────────────────
                  $tasks[$key] = function () use ($text, $target, $source): string {
                        if ($this->batchDelayMs > 0) {
                              usleep($this->batchDelayMs * 1_000);
                        }

                        return $this->translate($text, $target, $source);
                  };
            }

            // ── Run all pending tasks concurrently ────────────────────────────────
            if (!empty($tasks)) {
                  $batchResults = $this->runConcurrentBatches(
                        $tasks,
                        $this->concurrencyLimit
                  );

                  foreach ($batchResults as $key => $translated) {
                        $results[$key] = $translated;
                  }
            }

            ksort($results);

            return $results;
      }
}