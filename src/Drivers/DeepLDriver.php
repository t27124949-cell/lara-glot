<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DeepL REST API Translation Driver
 *
 * @see https://www.deepl.com/docs-api/translate-text/
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Overview
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Sends translation requests to the official DeepL REST API. DeepL natively
 * accepts an array of strings per request, so this driver:
 *  - Groups strings into chunks (default 50 — DeepL's limit is higher than LLMs).
 *  - Caches results at the individual string level so partial re-runs are fast.
 *  - Fans chunks out concurrently for maximum throughput.
 *  - Falls back to the original string on permanent failure (never throws to caller).
 *
 * Free vs Pro keys:
 *  Free-tier API keys end with ':fx' and must hit api-free.deepl.com.
 *  The constructor detects this automatically; you can override via config.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Config keys  (lara-glot.drivers.deepl.*)
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *  api_key        string  DeepL API key.                        (env DEEPL_API_KEY)
 *  base_url       string  API base URL (auto-detected from key).
 *  chunk_size     int     Strings per API call.                             (50)
 *  max_retries    int     Retry attempts per chunk.                           (3)
 *  concurrency    int     Max parallel chunk requests.                        (3)
 *  cache_enabled  bool    Whether to use the Laravel cache.               (true)
 *  cache_ttl      int     Cache lifetime in seconds.                     (86400)
 */
class DeepLDriver extends AbstractTranslationDriver
{
      /** DeepL API key — injected via config / env. */
      protected string $apiKey;

      /** Resolved API base URL (free or pro subdomain). */
      protected string $baseUrl;

      /**
       * How many strings to send in one DeepL API call.
       * DeepL's hard limit is 50 text elements per request.
       */
      protected int $chunkSize;

      // ─────────────────────────────────────────────────────────────────────────
      // Bootstrap
      // ─────────────────────────────────────────────────────────────────────────

      public function __construct()
      {
            $this->apiKey = (string) config(
                  'lara-glot.drivers.deepl.api_key',
                  env('DEEPL_API_KEY', '')
            );

            // Free-tier keys end with ':fx' and must hit the free subdomain.
            // Pro keys use the standard subdomain. Auto-detect unless overridden.
            $this->baseUrl = (string) config(
                  'lara-glot.drivers.deepl.base_url',
                  str_ends_with($this->apiKey, ':fx')
                  ? 'https://api-free.deepl.com/v2'
                  : 'https://api.deepl.com/v2'
            );

            // Shared properties declared in AbstractTranslationDriver.
            $this->chunkSize = (int) config('lara-glot.drivers.deepl.chunk_size', 50);
            $this->maxRetries = (int) config('lara-glot.drivers.deepl.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.deepl.retry_delay_ms', 500);
            $this->concurrencyLimit = (int) config('lara-glot.drivers.deepl.concurrency', 3);
            $this->cacheEnabled = (bool) config('lara-glot.drivers.deepl.cache_enabled', true);
            $this->cacheTtl = (int) config('lara-glot.drivers.deepl.cache_ttl', 2_592_000); // matches global LARAGLOT_CACHE_EXPIRY default
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Driver identity
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Unique snake_case name for this driver.
       * Used by the abstract class to build cache keys ("laraglot:deepl:...")
       * and log tags ("[LaraGlot:Deepl] ...").
       */
      protected function driverName(): string
      {
            return 'deepl';
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch translation  (main entry point)
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate a map of strings using DeepL.
       *
       * FLOW:
       *  1. Serve individual cache hits immediately — no API call needed.
       *  2. Split remaining strings into chunks of $chunkSize.
       *  3. Fan chunks out concurrently via runConcurrentBatches() (inherited).
       *  4. For each chunk result, write individual strings back to cache.
       *  5. Merge all results and restore the original key order.
       *
       * WHY STRING-LEVEL CACHING (not chunk-level as in OllamaDriver)?
       * DeepL is a cloud service billed per character. Caching at the string level
       * means a single changed string in a batch does not invalidate the entire
       * chunk. This is more cache-efficient for incremental translation workflows
       * (e.g. adding one new key to a language file).
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
            $pending = []; // strings that need an API call (cache-missed)

            // ── Pass 1: Serve cache hits ──────────────────────────────────────────
            foreach ($texts as $key => $text) {
                  // Preserve blank / non-string values without touching the API.
                  if (!is_string($text) || trim($text) === '') {
                        $results[$key] = $text;
                        continue;
                  }

                  // getFromCache() increments hit/miss counters automatically.
                  $cached = $this->getFromCache($this->getCacheKey($text, $target, $source));

                  if ($cached !== null) {
                        $results[$key] = $cached;
                  } else {
                        // Record for API translation in Pass 2.
                        $pending[$key] = $text;
                  }
            }

            if (empty($pending)) {
                  ksort($results);
                  return $results;
            }

            // ── Pass 2: Split cache-missed strings into chunks ────────────────────
            $chunks = array_chunk($pending, $this->chunkSize, true);
            $tasks = [];

            foreach ($chunks as $index => $chunk) {
                  // Each task is a closure capturing its own chunk — no shared state.
                  $tasks[$index] = fn() => $this->translateChunk($chunk, $target, $source);
            }

            // ── Pass 3: Run all chunks concurrently ───────────────────────────────
            // runConcurrentBatches() is inherited from AbstractTranslationDriver.
            // $concurrencyLimit IS the batch size — each batch runs fully in parallel,
            // and the next batch only starts once the current one completes.
            $batchResults = $this->runConcurrentBatches($tasks, $this->concurrencyLimit);

            // ── Pass 4: Merge chunk outputs and write to cache ────────────────────
            foreach ($batchResults as $chunkOutput) {
                  // $chunkOutput is an array of original-key → translated string.
                  foreach ($chunkOutput as $key => $translated) {
                        $results[$key] = $translated;

                        // Write each individual string to cache so future single-string
                        // or batch requests for the same text get an instant cache hit.
                        if (is_string($translated)) {
                              $this->putInCache(
                                    $this->getCacheKey($pending[$key], $target, $source),
                                    $translated
                              );
                        }
                  }
            }

            ksort($results);

            return $results;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Single chunk translation
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Send one chunk to the DeepL API and return a key-preserving result map.
       *
       * FLOW:
       *  1. Normalise locale codes to DeepL's required format (EN → EN-US, etc.).
       *  2. POST the array of values to /translate.
       *  3. Validate the response length matches the input.
       *  4. Re-map the translated values back onto the original keys.
       *  5. On permanent failure, return the original strings (graceful fallback).
       *
       * @param  array<int|string, string> $chunk
       * @return array<int|string, string>
       */
      protected function translateChunk(
            array $chunk,
            string $target,
            string $source
      ): array {
            $deepLTarget = $this->normalizeLocale($target);
            $deepLSource = $this->normalizeLocale($source);

            // Separate keys from values — DeepL only receives the values array.
            $keys = array_keys($chunk);
            $values = array_values($chunk);

            Log::info("{$this->logTag()} Sending chunk of " . count($chunk) . ' string(s) → ' . $deepLTarget);

            try {
                  return $this->withRetry(
                        function () use ($keys, $values, $deepLTarget, $deepLSource): array {

                              // ── Outbound API call ─────────────────────────────────────
                              $this->recordApiCall();

                              $response = Http::withHeaders([
                                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                                    'Content-Type' => 'application/json',
                              ])
                                    ->timeout(60)
                                    ->post("{$this->baseUrl}/translate", [
                                          'text' => $values,
                                          'source_lang' => $deepLSource,
                                          'target_lang' => $deepLTarget,
                                          // 'html' tells DeepL to preserve HTML tags inside strings.
                                          'tag_handling' => 'html',
                                          // Split on punctuation and newlines for better sentence alignment.
                                          'split_sentences' => '1',
                                          'preserve_formatting' => true,
                                    ]);

                              if (!$response->successful()) {
                                    throw new \RuntimeException(
                                          "DeepL HTTP {$response->status()}: {$response->body()}"
                                    );
                              }

                              $translations = $response->json('translations', []);

                              // ── Validate response length ──────────────────────────────
                              if (!is_array($translations) || count($translations) !== count($values)) {
                                    throw new \RuntimeException(sprintf(
                                          'DeepL returned %d result(s) for %d input(s).',
                                          is_array($translations) ? count($translations) : 0,
                                          count($values)
                                    ));
                              }

                              // ── Re-map onto original keys ─────────────────────────────
                              $mapped = [];

                              foreach ($keys as $i => $originalKey) {
                                    $mapped[$originalKey] = $this->normalizeTranslated(
                                          $translations[$i]['text'] ?? ''
                                    );
                              }

                              Log::info("{$this->logTag()} Chunk translated successfully.");

                              return $mapped;
                        },
                        $this->maxRetries,
                        $this->retryDelayMs, // from config: lara-glot.drivers.deepl.retry_delay_ms
                        ':DeepL'
                  );

            } catch (\Throwable $e) {
                  // Permanent failure after all retries — return originals so the
                  // caller always gets a usable array (never a thrown exception).
                  Log::error(
                        "{$this->logTag()} Chunk failed permanently after {$this->maxRetries} attempt(s).",
                        ['error' => $e->getMessage()]
                  );

                  // Re-map original values onto their original keys.
                  return array_combine($keys, $values);
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Locale normalisation
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Map generic two-letter locale codes to DeepL's required regional variants.
       *
       * DeepL mandates specific sub-codes for some language families; passing a bare
       * two-letter code for those languages results in a 400 API error.
       *
       * Common overrides:
       *  EN  → EN-US   (British English: EN-GB)
       *  PT  → PT-PT   (Brazilian Portuguese: PT-BR)
       *  ZH  → ZH-HANS (Traditional Chinese: ZH-HANT)
       *
       * All other codes are uppercased and passed through as-is.
       *
       * @see https://www.deepl.com/docs-api/translate-text/
       */
      protected function normalizeLocale(string $locale): string
      {
            return match (strtoupper($locale)) {
                  'EN' => 'EN-US',
                  'PT' => 'PT-PT',
                  'ZH' => 'ZH-HANS',
                  default => strtoupper($locale),
            };
      }
}
