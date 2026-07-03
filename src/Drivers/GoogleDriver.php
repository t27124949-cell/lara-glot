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
       *  4. Call the unofficial Google endpoint with retry/back-off.
       *     recordApiCall() is inside the closure so retries are counted accurately.
       *  5. Restore placeholders + normalize whitespace/entities.
       *  6. Write to cache, return to caller.
       *  7. On any failure: log the error and return the original string (graceful fallback).
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
                  // ── API call with retry ───────────────────────────────────────────
                  // recordApiCall() is INSIDE the closure so every HTTP attempt —
                  // including retries — increments the counter accurately.
                  $translated = $this->withRetry(
                        function () use ($protected, $source, $target): string {
                              // Count every actual outbound HTTP attempt, not just the first.
                              $this->recordApiCall();

                              // New instance per call = no shared state between concurrent tasks.
                              $client = new GoogleTranslate();

                              $result = $client
                                    ->setSource($source)
                                    ->setTarget($target)
                                    ->translate($protected);

                              if (!is_string($result) || trim($result) === '') {
                                    throw new \RuntimeException(
                                          'Google Translate returned an empty result.'
                                    );
                              }

                              return $result;
                        },
                        $this->maxRetries,
                        $this->retryDelayMs,
                        ':Google'
                  );

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