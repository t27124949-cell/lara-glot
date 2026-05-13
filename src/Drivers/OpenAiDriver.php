<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI-Compatible Chat Completion Translation Driver
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Overview
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Translates batches of strings using any provider that exposes the OpenAI
 * /chat/completions endpoint. Confirmed compatible with:
 *
 *  - OpenAI         (api.openai.com)         — gpt-4o-mini, gpt-4o, …
 *  - Azure OpenAI   (*.openai.azure.com)      — set base_url to your deployment
 *  - Groq           (api.groq.com/openai/v1)  — llama3-70b-8192, …
 *  - Together AI    (api.together.xyz/v1)      — Mixtral, Llama, …
 *  - Ollama (HTTP)  (localhost:11434/v1)       — local models via REST
 *
 * Set `lara-glot.drivers.openai.base_url` to switch provider without code changes.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Config keys  (lara-glot.drivers.openai.*)
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *  api_key        string  Provider API key.                    (env OPENAI_API_KEY)
 *  model          string  Chat model to use.                        ('gpt-4o-mini')
 *  base_url       string  API base URL.               ('https://api.openai.com/v1')
 *  chunk_size     int     Strings per API call.                             (20)
 *  max_retries    int     Retry attempts per chunk.                           (3)
 *  concurrency    int     Max parallel chunk requests.                        (3)
 *  cache_enabled  bool    Whether to use the Laravel cache.               (true)
 *  cache_ttl      int     Cache lifetime in seconds.                     (86400)
 */
class OpenAiDriver extends AbstractTranslationDriver
{
      /** Provider API key. */
      protected string $apiKey;

      /** Chat model identifier (e.g. "gpt-4o-mini", "llama3-70b-8192"). */
      protected string $model;

      /** API base URL, trailing slash stripped. */
      protected string $baseUrl;

      /**
       * How many strings to group into one chat-completion call.
       *
       * Keep this low enough that the prompt + response fits inside the model's
       * context window. 20 is a safe default for most models; raise it for
       * high-context models (e.g. gpt-4o with 128 k tokens).
       */
      protected int $chunkSize;

      // ─────────────────────────────────────────────────────────────────────────
      // Bootstrap
      // ─────────────────────────────────────────────────────────────────────────

      public function __construct()
      {
            $this->apiKey = (string) config(
                  'lara-glot.drivers.openai.api_key',
                  env('OPENAI_API_KEY', '')
            );

            $this->model = (string) config(
                  'lara-glot.drivers.openai.model',
                  'gpt-4o-mini'
            );

            // Strip trailing slash so we can safely append "/chat/completions".
            $this->baseUrl = rtrim(
                  (string) config(
                        'lara-glot.drivers.openai.base_url',
                        'https://api.openai.com/v1'
                  ),
                  '/'
            );

            // Shared properties declared in AbstractTranslationDriver.
            $this->chunkSize = (int) config('lara-glot.drivers.openai.chunk_size', 20);
            $this->maxRetries = (int) config('lara-glot.drivers.openai.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.openai.retry_delay_ms', 500);
            $this->concurrencyLimit = (int) config('lara-glot.drivers.openai.concurrency', 3);
            $this->cacheEnabled = (bool) config('lara-glot.drivers.openai.cache_enabled', true);
            $this->cacheTtl = (int) config('lara-glot.drivers.openai.cache_ttl', 2_592_000); // matches global LARAGLOT_CACHE_EXPIRY default
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Driver identity
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Unique snake_case name for this driver.
       * Used by the abstract class to build cache keys ("laraglot:openai:...")
       * and log tags ("[LaraGlot:Openai] ...").
       */
      protected function driverName(): string
      {
            return 'openai';
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch translation  (main entry point)
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate a map of strings using the chat-completion API.
       *
       * FLOW:
       *  1. Serve individual cache hits immediately — no API call needed.
       *  2. Split remaining strings into chunks of $chunkSize.
       *  3. Fan chunks out concurrently via runConcurrentBatches() (inherited).
       *  4. For each chunk result, write individual strings back to cache.
       *  5. Merge all results and restore the original key order.
       *
       * WHY STRING-LEVEL CACHING?
       * OpenAI is billed per token. Caching at the string level means a single
       * changed string in a batch does not cause a full chunk re-translation.
       * This is important for incremental language-file updates.
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
                  // Closures capture their own $chunk — no shared mutable state
                  // between parallel tasks.
                  $tasks[$index] = fn() => $this->translateChunk($chunk, $target, $source);
            }

            // ── Pass 3: Run all chunks concurrently ───────────────────────────────
            // runConcurrentBatches() is inherited from AbstractTranslationDriver.
            // $concurrencyLimit IS the batch size — each batch runs fully in parallel,
            // and the next batch only starts once the current one completes.
            $batchResults = $this->runConcurrentBatches($tasks, $this->concurrencyLimit);

            // ── Pass 4: Merge chunk outputs and write individual strings to cache ──
            foreach ($batchResults as $chunkOutput) {
                  foreach ($chunkOutput as $key => $translated) {
                        $results[$key] = $translated;

                        // Cache each string individually so future requests for the
                        // same text — even in a different batch — get an instant hit.
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
       * Send one chunk to the chat-completion endpoint and return a key-preserving map.
       *
       * FLOW:
       *  1. JSON-encode the chunk values (only values — keys are internal).
       *  2. POST to /chat/completions with the system prompt and JSON input.
       *  3. Extract and clean the model's reply.
       *  4. Decode the JSON array and validate the element count.
       *  5. Re-map decoded values onto the original keys.
       *  6. On permanent failure, return the original strings (graceful fallback).
       *
       * @param  array<int|string, string> $chunk
       * @return array<int|string, string>
       */
      protected function translateChunk(
            array $chunk,
            string $target,
            string $source
      ): array {
            $keys = array_keys($chunk);
            $values = array_values($chunk);

            Log::info("{$this->logTag()} Sending chunk of " . count($chunk) . ' string(s) → ' . $target);

            // ── JSON-encode input ─────────────────────────────────────────────────
            // Encode before the retry loop so a JSON encoding failure is treated
            // as a non-retryable error (bad input won't fix itself on retry).
            try {
                  $jsonInput = json_encode(
                        $values,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                  );
            } catch (\JsonException $e) {
                  Log::error("{$this->logTag()} Failed to JSON-encode chunk.", [
                        'error' => $e->getMessage(),
                  ]);

                  // Return originals immediately — retrying won't fix a bad input.
                  return array_combine($keys, $values);
            }

            try {
                  return $this->withRetry(
                        function () use ($keys, $values, $jsonInput, $target, $source): array {

                              // ── Outbound API call ─────────────────────────────────────
                              $this->recordApiCall();

                              $response = Http::withToken($this->apiKey)
                                    ->timeout(90)
                                    ->post("{$this->baseUrl}/chat/completions", [
                                          'model' => $this->model,
                                          // temperature 0 = deterministic, consistent output —
                                          // critical for translation; we don't want creative variation.
                                          'temperature' => 0,
                                          'messages' => [
                                                [
                                                      'role' => 'system',
                                                      'content' => $this->buildSystemPrompt($source, $target),
                                                ],
                                                [
                                                      // Pass the JSON array as the user message so the
                                                      // model treats it as the thing to translate.
                                                      'role' => 'user',
                                                      'content' => $jsonInput,
                                                ],
                                          ],
                                    ]);

                              if (!$response->successful()) {
                                    throw new \RuntimeException(
                                          "OpenAI HTTP {$response->status()}: {$response->body()}"
                                    );
                              }

                              // Extract the assistant's reply — typically the only content block.
                              $raw = trim((string) $response->json('choices.0.message.content', ''));

                              // decodeJsonResponse() is provided by the DecodesJsonResponse trait.
                              // It strips markdown fences and throws on decode failure.
                              $decoded = $this->decodeJsonResponse($raw, 'OpenAI');

                              // ── Validate response length ──────────────────────────────
                              // The model must return exactly as many items as we sent.
                              if (!is_array($decoded) || count($decoded) !== count($values)) {
                                    throw new \RuntimeException(sprintf(
                                          'OpenAI returned %s result(s) for %d input(s). Raw: %s',
                                          is_array($decoded) ? count($decoded) : 'null',
                                          count($values),
                                          mb_substr($raw, 0, 300)
                                    ));
                              }

                              // ── Re-map onto original keys ─────────────────────────────
                              $mapped = [];

                              foreach ($keys as $i => $originalKey) {
                                    // normalizeTranslated() trims + decodes HTML entities.
                                    $mapped[$originalKey] = $this->normalizeTranslated($decoded[$i]);
                              }

                              Log::info("{$this->logTag()} Chunk translated successfully.");

                              return $mapped;
                        },
                        $this->maxRetries,
                        $this->retryDelayMs, // from config: lara-glot.drivers.openai.retry_delay_ms
                        ':OpenAI'
                  );

            } catch (\Throwable $e) {
                  // Permanent failure after all retries — return originals so the
                  // caller always gets a usable array (never a thrown exception).
                  Log::error(
                        "{$this->logTag()} Chunk failed permanently after {$this->maxRetries} attempt(s).",
                        ['error' => $e->getMessage()]
                  );

                  return array_combine($keys, $values);
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // System prompt
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Build the system prompt that scopes the model's behaviour.
       *
       * DESIGN NOTES:
       *  - Temperature is already set to 0 in the API call. The prompt reinforces
       *    determinism by saying "non-negotiable rules".
       *  - Placeholder token patterns are listed explicitly so the model learns
       *    to recognise and preserve the opaque keys used by ProtectsPlaceholders.
       *  - Instructing the model to return ONLY a JSON array (no fences, no prose)
       *    is essential — even a single stray word breaks JSON decoding.
       *
       * @param  string $source  Source language code (e.g. "en").
       * @param  string $target  Target language code (e.g. "fr").
       * @return string
       */
      private function buildSystemPrompt(string $source, string $target): string
      {
            return <<<PROMPT
You are a professional translator. Translate the JSON array from "{$source}" to "{$target}".

RULES (non-negotiable):
- Respond with ONLY a valid JSON array — no markdown fences, no explanation, no wrapper object.
- Preserve the exact element count and order.
- Translate only visible human-readable text; do NOT translate or modify these tokens:
    __HTML_N__   __URL_N__   __VAR_N__   :name   :count   (and any similar patterns)
- Preserve all HTML tags exactly as written.
- Do not add, remove, or reorder elements.
PROMPT;
      }
}
