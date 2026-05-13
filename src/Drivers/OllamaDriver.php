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
 *  - Groups strings into chunks (default 10) to reduce round-trips.
 *  - Fans out chunks concurrently (default 2 parallel) to use spare GPU/CPU.
 *  - Uses forced JSON mode (`->format('json')`) for reliable parsing.
 *  - Caches results aggressively (24 h default) to avoid re-translating.
 *  - Retries with a 1-second base delay (local models can be slow to respond).
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Config keys  (lara-glot.drivers.ollama.*)
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *  model          string  Ollama model tag to use.                  ('llama3')
 *  chunk_size     int     Strings per API call.                         (10)
 *  max_retries    int     Retry attempts per chunk.                       (3)
 *  concurrency    int     Max parallel chunk requests.                    (2)
 *  cache_enabled  bool    Whether to use the Laravel cache.           (true)
 *  cache_ttl      int     Cache lifetime in seconds.                (86400)
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
       *
       * Larger chunks mean fewer round-trips but longer individual responses.
       * Keep below the model's context window minus the system prompt overhead.
       */
      protected int $chunkSize;

      // ─────────────────────────────────────────────────────────────────────────
      // Bootstrap
      // ─────────────────────────────────────────────────────────────────────────

      public function __construct()
      {
            // Hydrate all shared properties declared in AbstractTranslationDriver
            // from the driver-specific config namespace.
            $this->model = (string) config('lara-glot.drivers.ollama.model', 'llama3');
            $this->chunkSize = (int) config('lara-glot.drivers.ollama.chunk_size', 15);         // matches config default
            $this->maxRetries = (int) config('lara-glot.drivers.ollama.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.ollama.retry_delay_ms', 1_000);     // local LLMs need more breathing room
            $this->concurrencyLimit = (int) config('lara-glot.drivers.ollama.concurrency', 2);
            $this->cacheEnabled = (bool) config('lara-glot.drivers.ollama.cache_enabled', true);
            $this->cacheTtl = (int) config('lara-glot.drivers.ollama.cache_ttl', 2_592_000); // matches global LARAGLOT_CACHE_EXPIRY default
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Driver identity
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Unique snake_case name for this driver.
       * Used by the abstract class to build cache keys ("laraglot:ollama:...")
       * and log tags ("[LaraGlot:Ollama] ...").
       */
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
       *  4. Merge chunk results and restore original key order.
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
                  // Build a cache key that represents the entire chunk as a unit.
                  $cacheKey = $this->buildChunkCacheKey($chunk, $target, $source);

                  // ── Full chunk cache hit ──────────────────────────────────────────
                  // getFromCache() increments the hit counter and returns null on miss.
                  $cached = $this->getFromCache($cacheKey);

                  if ($cached !== null) {
                        // The cached value is a JSON-encoded map of key → translated string.
                        $chunkResults = json_decode($cached, true);

                        if (is_array($chunkResults)) {
                              foreach ($chunkResults as $key => $value) {
                                    $results[$key] = $value;
                              }
                              continue; // Skip to the next chunk — no API call needed.
                        }
                  }

                  // ── Enqueue as a concurrent task ──────────────────────────────────
                  // $index is the chunk index (0, 1, 2, …); $chunk is the slice of $texts.
                  $tasks[$index] = fn() => $this->translateChunkWithRetry(
                        $chunk,
                        $target,
                        $source,
                        $cacheKey
                  );
            }

            // ── Run all pending chunks concurrently ───────────────────────────────
            // runConcurrentBatches() is inherited from AbstractTranslationDriver.
            // It processes $tasks in groups of $concurrencyLimit to avoid overloading
            // the local Ollama server.
            if (!empty($tasks)) {
                  // $concurrencyLimit IS the batch size — each batch runs fully in parallel,
                  // and the next batch only starts once the current one completes.
                  $batchResults = $this->runConcurrentBatches($tasks, $this->concurrencyLimit);

                  foreach ($batchResults as $chunkOutput) {
                        // Each task returns an array of translated key → value pairs.
                        foreach ($chunkOutput as $key => $value) {
                              $results[$key] = $value;
                        }
                  }
            }

            // Restore original key order before returning.
            ksort($results);

            return $results;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Chunk-level retry wrapper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Attempt to translate a chunk, retrying up to $maxRetries times.
       *
       * On success the translated map is JSON-encoded and stored in the cache
       * under $cacheKey so the next call for the same chunk is a full hit.
       *
       * On final failure, withRetry() re-throws the last exception, which bubbles
       * up through runConcurrentBatches() and is ultimately caught by the caller.
       *
       * @param  array<int|string, string> $chunk
       * @return array<int|string, string>
       *
       * @throws \Throwable
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
                        $this->putInCache($cacheKey, json_encode($translated, JSON_UNESCAPED_UNICODE));

                        return $translated;
                  },
                  $this->maxRetries,
                  $this->retryDelayMs, // from config: lara-glot.drivers.ollama.retry_delay_ms
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
       *  1. Encode the chunk values as a JSON array (ordered, no key info).
       *  2. Ask Ollama using format('json') to guarantee a parseable response.
       *  3. Strip any residual markdown fences (some models ignore format:'json').
       *  4. Decode the JSON and validate length matches input.
       *  5. Re-map decoded values back onto original keys.
       *
       * Throws on any error so withRetry() can catch and re-attempt.
       *
       * @param  array<int|string, string> $chunk
       * @return array<int|string, string>
       *
       * @throws \Throwable
       */
      protected function translateChunk(
            array $chunk,
            string $target,
            string $source
      ): array {
            // Separate the original keys from the values so we can re-map later.
            // Ollama only gets the values (as a plain JSON array); keys are internal.
            $values = array_values($chunk);
            $keys = array_keys($chunk);

            $jsonInput = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            try {
                  // ── Outbound API call ─────────────────────────────────────────────
                  $this->recordApiCall();

                  $response = Ollama::model($this->model)
                        // format('json') instructs Ollama to constrain its output to
                        // valid JSON — critical for reliable automated parsing.
                        ->format('json')
                        ->prompt($this->buildPrompt($source, $target, $jsonInput))
                        ->options([
                              // temperature 0 = deterministic output; we want consistency,
                              // not creative variation, for translation.
                              'temperature' => 0,
                              // Enough context for a reasonably large chunk; adjust if
                              // you increase $chunkSize significantly.
                              'num_ctx' => 2048,
                        ])
                        ->ask();

                  // ── Response extraction ───────────────────────────────────────────
                  $raw = trim((string) ($response['response'] ?? ''));

                  // Some models add ```json … ``` fences even when format:'json' is set.
                  // Strip them so json_decode() does not choke on the backtick wrapping.
                  $raw = preg_replace('/^```json\s*|\s*```$/i', '', $raw);

                  // decodeJsonResponse() is provided by the DecodesJsonResponse trait
                  // and throws a descriptive RuntimeException on decode failure.
                  $decoded = $this->decodeJsonResponse($raw, 'Ollama');

                  // ── Validation ────────────────────────────────────────────────────
                  // The model must return exactly as many strings as we sent.
                  // A count mismatch means the response is unusable; throw to retry.
                  if (!is_array($decoded) || count($decoded) !== count($values)) {
                        throw new \RuntimeException(
                              sprintf(
                                    'Ollama returned %d items; expected %d.',
                                    is_array($decoded) ? count($decoded) : 0,
                                    count($values)
                              )
                        );
                  }

                  // ── Re-map onto original keys ─────────────────────────────────────
                  $mapped = [];

                  foreach ($keys as $i => $originalKey) {
                        // normalizeTranslated() trims whitespace and decodes HTML entities.
                        $mapped[$originalKey] = $this->normalizeTranslated($decoded[$i]);
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
       * DESIGN NOTES:
       *  - Keep the prompt short when using format:'json'. Long, elaborate prompts
       *    cause some models to wrap their reply in prose before the JSON object.
       *  - Explicitly mention placeholder tokens (:name, __VAR_N__) so the model
       *    knows NOT to translate them.
       *  - Instruct the model to preserve order — the re-mapping step depends on it.
       *
       * @param  string $source     Source language code (e.g. "en").
       * @param  string $target     Target language code (e.g. "fr").
       * @param  string $jsonInput  JSON-encoded array of strings to translate.
       * @return string
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
