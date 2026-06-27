<?php

namespace Tonydev\LaraGlot\Drivers;

use Cloudstudio\Ollama\Facades\Ollama;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ollama Driver — Local LLM Translation via cloudstudio/ollama
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Overview
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Sends batches of strings to a locally-hosted Ollama model (e.g. llama3,
 * mistral, phi3) and parses the JSON array it returns.
 *
 * Because Ollama is self-hosted there is no per-call monetary cost, but latency
 * is higher than cloud APIs. The driver therefore:
 *  - Groups strings into chunks (default 15) to reduce round-trips.
 *  - Fans out chunks concurrently (default 2 parallel) to use spare GPU/CPU.
 *  - Uses forced JSON mode (`->format('json')`) for reliable parsing.
 *  - Caches results aggressively (30 days default) to avoid re-translating.
 *  - Retries with a 1-second base delay (local models can be slow to respond).
 *  - Falls back to original strings on permanent failure (never throws to caller).
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Config keys  (lara-glot.drivers.ollama.*)
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *  model          string  Ollama model tag to use.                  ('llama3')
 *  chunk_size     int     Strings per API call.                         (15)
 *  max_retries    int     Retry attempts per chunk.                       (3)
 *  retry_delay_ms int     Base delay between retries in ms.           (1000)
 *  concurrency    int     Max parallel chunk requests.                    (2)
 *  cache_enabled  bool    Whether to use the Laravel cache.           (true)
 *  cache_ttl      int     Cache lifetime in seconds.              (2592000)
 */
class OllamaDriver extends AbstractTranslationDriver
{
      /**
       * Ollama model tag (e.g. "llama3", "mistral", "phi3").
       * Resolved from config so operators can swap models without code changes.
       */
      protected string $model;

      /**
       * How many strings to group into one API call.
       * Keep below the model's context window minus system prompt overhead.
       */
      protected int $chunkSize;

      // ─────────────────────────────────────────────────────────────────────────
      // Bootstrap
      // ─────────────────────────────────────────────────────────────────────────

      public function __construct()
      {
            $this->model = (string) config('lara-glot.drivers.ollama.model', 'llama3');
            $this->chunkSize = (int) config('lara-glot.drivers.ollama.chunk_size', 15);
            $this->maxRetries = (int) config('lara-glot.drivers.ollama.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.ollama.retry_delay_ms', 1_000);
            $this->concurrencyLimit = (int) config('lara-glot.drivers.ollama.concurrency', 2);
            $this->cacheEnabled = (bool) config('lara-glot.drivers.ollama.cache_enabled', true);
            $this->cacheTtl = (int) config('lara-glot.drivers.ollama.cache_ttl', 2_592_000);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Driver identity
      // ─────────────────────────────────────────────────────────────────────────

      protected function driverName(): string
      {
            return 'ollama';
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch translation  (main entry point)
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate a map of strings using Ollama.
       *
       * FLOW:
       *  1. Split $texts into chunks of $chunkSize.
       *  2. For each chunk: check whether it is fully cached.
       *     - Full cache hit  → merge into $results immediately, no API call.
       *     - Any cache miss  → enqueue as a concurrent task.
       *  3. Fan all tasks out with runConcurrentBatches() (2 parallel by default).
       *  4. Merge chunk results — on permanent failure fall back to originals.
       *  5. Restore original key order.
       *
       * WHY CHUNK-LEVEL CACHING (not string-level)?
       * Ollama translates a JSON array in one shot. If all strings in a chunk
       * were previously translated we can skip the entire API call. This is more
       * efficient than checking each string individually when the same set of
       * strings is re-translated (e.g. CI rebuilds of language files).
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

            // Split into equal-sized chunks, preserving original keys.
            $chunks = array_chunk($texts, $this->chunkSize, true);

            foreach ($chunks as $index => $chunk) {
                  $cacheKey = $this->buildChunkCacheKey($chunk, $target, $source);

                  // ── Full chunk cache hit ──────────────────────────────────────────
                  $cached = $this->getFromCache($cacheKey);

                  if ($cached !== null) {
                        $chunkResults = json_decode($cached, true);

                        if (is_array($chunkResults)) {
                              foreach ($chunkResults as $key => $value) {
                                    $results[$key] = $value;
                              }
                              continue;
                        }
                  }

                  // ── Enqueue as a concurrent task ──────────────────────────────────
                  $tasks[$index] = fn() => $this->translateChunkWithRetry(
                        $chunk,
                        $target,
                        $source,
                        $cacheKey
                  );
            }

            // ── Run all pending chunks concurrently ───────────────────────────────
            if (!empty($tasks)) {
                  $batchResults = $this->runConcurrentBatches($tasks, $this->concurrencyLimit);

                  foreach ($batchResults as $index => $chunkOutput) {
                        // ── Graceful fallback on permanent failure ────────────────────
                        // translateChunkWithRetry() re-throws after all retries are
                        // exhausted. Concurrency::run() surfaces that as a Throwable in
                        // the results array rather than crashing the whole batch.
                        // We catch it here and return the original strings for this chunk.
                        if ($chunkOutput instanceof Throwable) {
                              Log::error(
                                    "{$this->logTag()} Chunk {$index} failed permanently, using originals.",
                                    ['error' => $chunkOutput->getMessage()]
                              );

                              foreach ($chunks[$index] as $key => $original) {
                                    $results[$key] = $original;
                              }

                              continue;
                        }

                        foreach ($chunkOutput as $key => $value) {
                              $results[$key] = $value;
                        }
                  }
            }

            ksort($results);

            return $results;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Chunk-level retry wrapper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Attempt to translate a chunk, retrying up to $maxRetries times.
       *
       * On success the translated map is JSON-encoded and stored in the cache.
       * On final failure, withRetry() re-throws — caught in translateBatch().
       *
       * @param  array<int|string, string> $chunk
       * @return array<int|string, string>
       * @throws Throwable
       */
      protected function translateChunkWithRetry(
            array $chunk,
            string $target,
            string $source,
            string $cacheKey
      ): array {
            return $this->withRetry(
                  function () use ($chunk, $target, $source, $cacheKey): array {
                        $translated = $this->translateChunk($chunk, $target, $source);

                        // Cache the entire chunk as a JSON-encoded map.
                        // JSON encoding preserves non-integer keys (unlike serialize()).
                        $this->putInCache(
                              $cacheKey,
                              json_encode($translated, JSON_UNESCAPED_UNICODE)
                        );

                        return $translated;
                  },
                  $this->maxRetries,
                  $this->retryDelayMs,
                  ':Ollama'
            );
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Single chunk translation
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Send one chunk to Ollama and parse its JSON response.
       *
       * FLOW:
       *  1. Protect placeholders (:name, URLs, <span translate="no">) so the
       *     model receives opaque tokens it cannot mangle.
       *  2. Encode protected values as a JSON array and send to Ollama.
       *  3. Decode the response via DecodesJsonResponse trait (handles fences,
       *     BOM, envelope objects, etc.).
       *  4. Validate response length matches input.
       *  5. Re-map decoded values onto original keys + restore placeholders.
       *
       * Throws on any error so withRetry() can catch and re-attempt.
       *
       * @param  array<int|string, string> $chunk
       * @return array<int|string, string>
       * @throws Throwable
       */
      protected function translateChunk(
            array $chunk,
            string $target,
            string $source
      ): array {
            $keys = array_keys($chunk);
            $values = array_values($chunk);

            // ── Protect placeholders BEFORE sending to Ollama ─────────────────────
            // Replaces :name → __VAR_0__, URLs → __URL_1__, etc.
            // The prompt also instructs the model to leave these tokens alone, but
            // placeholder protection is the reliable guarantee — prompts are not.
            $protectedValues = [];
            $placeholderMaps = [];

            foreach ($values as $i => $text) {
                  [$protectedValues[$i], $placeholderMaps[$i]] = $this->protectPlaceholders($text);
            }

            $jsonInput = json_encode($protectedValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            try {
                  // ── Outbound API call ─────────────────────────────────────────────
                  $this->recordApiCall();

                  $response = Ollama::model($this->model)
                        // format('json') constrains output to valid JSON.
                        ->format('json')
                        ->prompt($this->buildPrompt($source, $target, $jsonInput))
                        ->options([
                              // temperature 0 = deterministic, consistent translations.
                              'temperature' => 0,
                              // Enough context for a reasonably sized chunk.
                              'num_ctx' => 2048,
                        ])
                        ->ask();

                  // ── Response extraction ───────────────────────────────────────────
                  $raw = trim((string) ($response['response'] ?? ''));

                  // decodeJsonResponse() (from DecodesJsonResponse trait) handles:
                  // markdown fences, BOM, CRLF, envelope objects, re-indexing.
                  // No need to manually strip fences here — the trait does it.
                  $decoded = $this->decodeJsonResponse($raw, 'Ollama');

                  // ── Validation ────────────────────────────────────────────────────
                  if (!is_array($decoded) || count($decoded) !== count($values)) {
                        throw new \RuntimeException(sprintf(
                              'Ollama returned %d item(s); expected %d.',
                              is_array($decoded) ? count($decoded) : 0,
                              count($values)
                        ));
                  }

                  // ── Re-map onto original keys + restore placeholders ───────────────
                  $mapped = [];

                  foreach ($keys as $i => $originalKey) {
                        $normalized = $this->normalizeTranslated($decoded[$i]);
                        $mapped[$originalKey] = $this->restorePlaceholders(
                              $normalized,
                              $placeholderMaps[$i]
                        );
                  }

                  return $mapped;

            } catch (Throwable $e) {
                  Log::error("{$this->logTag()} Chunk translation failed.", [
                        'target' => $target,
                        'source' => $source,
                        'error' => $e->getMessage(),
                  ]);

                  // Re-throw so withRetry() can schedule the next attempt.
                  throw $e;
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Prompt builder
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Build the Ollama prompt for a translation chunk.
       *
       * Keep it short — long prompts cause some models to wrap their reply in
       * prose before the JSON object, defeating format('json').
       */
      private function buildPrompt(string $source, string $target, string $jsonInput): string
      {
            return <<<PROMPT
You are a professional translator. Translate this JSON array from "{$source}" to "{$target}".
Output must be a JSON array of strings only. Same length as input. Preserve order.
Do not translate or modify tokens like :name, :count, __VAR_0__, __URL_1__, __HTML_2__.

Input:
{$jsonInput}
PROMPT;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Chunk-level cache key
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Build a cache key that represents an entire chunk as a unit.
       *
       * WHY NOT REUSE getCacheKey()?
       * getCacheKey() (from the abstract class) is keyed on a single string.
       * Here we want a key that covers the entire chunk so a single Cache::get()
       * can determine whether all strings in the chunk are already translated.
       *
       * We JSON-encode the chunk (including its original keys) before hashing so
       * that the same strings in a different order produce a different cache key —
       * order matters for the re-mapping step.
       *
       * NOTE: md5() is used here (not sha256) because the chunk key is never
       * stored persistently as a user-facing identifier — it is purely an
       * internal cache lookup key, and md5 is faster for that purpose.
       *
       * @param  array<int|string, string> $chunk
       */
      protected function buildChunkCacheKey(
            array $chunk,
            string $target,
            string $source
      ): string {
            return sprintf(
                  'laraglot:ollama:%s:%s:chunk:%s',
                  $source,
                  $target,
                  md5(json_encode($chunk))
            );
      }
}