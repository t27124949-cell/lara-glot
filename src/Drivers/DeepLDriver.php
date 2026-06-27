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
 *  cache_ttl      int     Cache lifetime in seconds.                  (2592000)
 */
class DeepLDriver extends AbstractTranslationDriver
{
      /** Correct log label — avoids ucfirst() producing "Deepl" instead of "DeepL". */
      protected string $logName = 'DeepL';

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
            $this->apiKey = (string) config('lara-glot.drivers.deepl.api_key', '');

            // Free-tier keys end with ':fx' and must hit the free subdomain.
            // Pro keys use the standard subdomain. Auto-detect unless overridden.
            $configuredUrl = config('lara-glot.drivers.deepl.base_url');
            $this->baseUrl = $configuredUrl ?: (
                  str_ends_with($this->apiKey, ':fx')
                  ? 'https://api-free.deepl.com/v2'
                  : 'https://api.deepl.com/v2'
            );

            $this->chunkSize = (int) config('lara-glot.drivers.deepl.chunk_size', 50);
            $this->maxRetries = (int) config('lara-glot.drivers.deepl.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.deepl.retry_delay_ms', 500);
            $this->concurrencyLimit = (int) config('lara-glot.drivers.deepl.concurrency', 3);
            $this->cacheEnabled = (bool) config('lara-glot.drivers.deepl.cache_enabled', true);
            $this->cacheTtl = (int) config('lara-glot.drivers.deepl.cache_ttl', 2_592_000);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Driver identity
      // ─────────────────────────────────────────────────────────────────────────

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
       * @param  array<int|string, string> $texts
       * @return array<int|string, string>
       */
      public function translateBatch(
            array $texts,
            string $target,
            string $source = 'en'
      ): array {
            $results = [];
            $pending = [];

            // ── Pass 1: Serve cache hits ──────────────────────────────────────────
            foreach ($texts as $key => $text) {
                  if (!is_string($text) || trim($text) === '') {
                        $results[$key] = $text;
                        continue;
                  }

                  $cached = $this->getFromCache($this->getCacheKey($text, $target, $source));

                  if ($cached !== null) {
                        $results[$key] = $cached;
                  } else {
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
                  $tasks[$index] = fn() => $this->translateChunk($chunk, $target, $source);
            }

            // ── Pass 3: Run all chunks concurrently ───────────────────────────────
            $batchResults = $this->runConcurrentBatches($tasks, $this->concurrencyLimit);

            // ── Pass 4: Merge chunk outputs and write individual strings to cache ──
            foreach ($batchResults as $chunkOutput) {
                  foreach ($chunkOutput as $key => $translated) {
                        $results[$key] = $translated;

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

            $keys = array_keys($chunk);
            $values = array_values($chunk);

            // ── Protect placeholders BEFORE sending to DeepL ─────────────────────
            // Builds $protectedValues (safe to send) and $placeholderMaps (for restore).
            $protectedValues = [];
            $placeholderMaps = [];

            foreach ($values as $i => $text) {
                  [$protectedValues[$i], $placeholderMaps[$i]] = $this->protectPlaceholders($text);
            }

            Log::info("{$this->logTag()} Sending chunk of " . count($chunk) . " string(s) → {$deepLTarget}");

            try {
                  // $placeholderMaps is captured in use() so restore can happen inside.
                  return $this->withRetry(
                        function () use ($keys, $protectedValues, $placeholderMaps, $deepLTarget, $deepLSource): array {

                              $this->recordApiCall();

                              $response = Http::withHeaders([
                                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                                    'Content-Type' => 'application/json',
                              ])
                                    ->timeout(60)
                                    ->post("{$this->baseUrl}/translate", [
                                          'text' => $protectedValues,
                                          'source_lang' => $deepLSource,
                                          'target_lang' => $deepLTarget,
                                          'tag_handling' => 'html',
                                          'split_sentences' => 1,
                                          'preserve_formatting' => true,
                                    ]);

                              if (!$response->successful()) {
                                    throw new \RuntimeException(
                                          "DeepL HTTP {$response->status()}: {$response->body()}"
                                    );
                              }

                              $translations = $response->json('translations', []);

                              if (!is_array($translations) || count($translations) !== count($protectedValues)) {
                                    throw new \RuntimeException(sprintf(
                                          'DeepL returned %d result(s) for %d input(s).',
                                          is_array($translations) ? count($translations) : 0,
                                          count($protectedValues)
                                    ));
                              }

                              // ── Re-map onto original keys + restore placeholders ──────
                              $mapped = [];

                              foreach ($keys as $i => $originalKey) {
                                    $normalized = $this->normalizeTranslated(
                                          $translations[$i]['text'] ?? ''
                                    );
                                    // Restore :name, URLs, <span translate="no"> etc.
                                    $mapped[$originalKey] = $this->restorePlaceholders(
                                          $normalized,
                                          $placeholderMaps[$i]
                                    );
                              }

                              Log::info("{$this->logTag()} Chunk translated successfully.");

                              return $mapped;
                        },
                        $this->maxRetries,
                        $this->retryDelayMs,
                        ':DeepL'
                  );

            } catch (\Throwable $e) {
                  Log::error(
                        "{$this->logTag()} Chunk failed permanently after {$this->maxRetries} attempt(s).",
                        ['error' => $e->getMessage()]
                  );

                  // Fallback: restore placeholders on original values so callers
                  // always receive clean strings — never raw __VAR_0__ tokens.
                  $fallback = [];

                  foreach ($keys as $i => $originalKey) {
                        $fallback[$originalKey] = $this->restorePlaceholders(
                              $values[$i] ?? '',
                              $placeholderMaps[$i]
                        );
                  }

                  return $fallback;
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
