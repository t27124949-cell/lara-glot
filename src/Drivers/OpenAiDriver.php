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
 *  chunk_size     int     Strings per API call.                             (30)
 *  max_retries    int     Retry attempts per chunk.                           (3)
 *  retry_delay_ms int     Base delay between retries in ms.                (500)
 *  concurrency    int     Max parallel chunk requests.                        (3)
 *  cache_enabled  bool    Whether to use the Laravel cache.               (true)
 *  cache_ttl      int     Cache lifetime in seconds.                  (2592000)
 */
class OpenAiDriver extends AbstractTranslationDriver
{
      /** Correct log label — avoids ucfirst() producing "Openai" instead of "OpenAI". */
      protected string $logName = 'OpenAI';

      /** Provider API key. */
      protected string $apiKey;

      /** Chat model identifier (e.g. "gpt-4o-mini", "llama3-70b-8192"). */
      protected string $model;

      /** API base URL, trailing slash stripped. */
      protected string $baseUrl;

      /**
       * How many strings to group into one chat-completion call.
       * Keep low enough that prompt + response fits inside the model's context window.
       */
      protected int $chunkSize;

      // ─────────────────────────────────────────────────────────────────────────
      // Bootstrap
      // ─────────────────────────────────────────────────────────────────────────

      public function __construct()
      {
            // Config already calls env() internally — no need to double-wrap.
            $this->apiKey = (string) config('lara-glot.drivers.openai.api_key', '');
            $this->model = (string) config('lara-glot.drivers.openai.model', 'gpt-4o-mini');

            // Strip trailing slash so we can safely append "/chat/completions".
            $this->baseUrl = rtrim(
                  (string) config('lara-glot.drivers.openai.base_url', 'https://api.openai.com/v1'),
                  '/'
            );

            $this->chunkSize = (int) config('lara-glot.drivers.openai.chunk_size', 30);
            $this->maxRetries = (int) config('lara-glot.drivers.openai.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.openai.retry_delay_ms', 500);
            $this->concurrencyLimit = (int) config('lara-glot.drivers.openai.concurrency', 3);
            $this->cacheEnabled = (bool) config('lara-glot.drivers.openai.cache_enabled', true);
            $this->cacheTtl = (int) config('lara-glot.drivers.openai.cache_ttl', 2_592_000);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Driver identity
      // ─────────────────────────────────────────────────────────────────────────

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
       * OpenAI is billed per token. String-level caching means a single changed
       * string does not invalidate the whole chunk — important for incremental
       * language-file updates.
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

            // ── Pass 4: Merge chunk outputs + write individual strings to cache ────
            foreach ($batchResults as $chunkOutput) {
                  foreach ($chunkOutput as $key => $translated) {
                        $results[$key] = $translated;

                        // Cache against the original (unprotected) text so any future
                        // lookup — single-string or batch — gets an instant hit.
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
       *  1. Protect placeholders (:name, URLs, <span translate="no">) → opaque tokens.
       *  2. JSON-encode the protected values.
       *  3. POST to /chat/completions with system prompt + JSON input.
       *  4. Decode the response via DecodesJsonResponse trait.
       *  5. Validate element count, re-map onto original keys, restore placeholders.
       *  6. On permanent failure: return original strings (graceful fallback).
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

            // ── Protect placeholders BEFORE sending to the model ──────────────────
            // Builds $protectedValues (safe to send) and $placeholderMaps (for restore).
            // This is the reliable guarantee — the system prompt is only a hint.
            $protectedValues = [];
            $placeholderMaps = [];

            foreach ($values as $i => $text) {
                  [$protectedValues[$i], $placeholderMaps[$i]] = $this->protectPlaceholders($text);
            }

            Log::info("{$this->logTag()} Sending chunk of " . count($chunk) . " string(s) → {$target}");

            // ── JSON-encode protected input ───────────────────────────────────────
            // Done outside the retry closure — a JSON encoding failure is not
            // retryable (bad input won't fix itself on the next attempt).
            try {
                  $jsonInput = json_encode(
                        $protectedValues,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                  );
            } catch (\JsonException $e) {
                  Log::error("{$this->logTag()} Failed to JSON-encode chunk.", [
                        'error' => $e->getMessage(),
                  ]);

                  return array_combine($keys, $values); // return originals immediately
            }

            try {
                  // $placeholderMaps captured in use() so restore can happen inside.
                  return $this->withRetry(
                        function () use ($keys, $values, $protectedValues, $placeholderMaps, $jsonInput, $target, $source): array {

                              // ── Outbound API call ─────────────────────────────────────
                              $this->recordApiCall();

                              $response = Http::withToken($this->apiKey)
                                    ->timeout(90)
                                    ->post("{$this->baseUrl}/chat/completions", [
                                          'model' => $this->model,
                                          // temperature 0 = deterministic, no creative variation.
                                          'temperature' => 0,
                                          'messages' => [
                                                [
                                                      'role' => 'system',
                                                      'content' => $this->buildSystemPrompt($source, $target),
                                                ],
                                                [
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

                              $raw = trim((string) $response->json('choices.0.message.content', ''));

                              // decodeJsonResponse() (DecodesJsonResponse trait) handles
                              // fences, BOM, envelope objects, re-indexing.
                              $decoded = $this->decodeJsonResponse($raw, 'OpenAI');

                              // ── Validate response length ──────────────────────────────
                              if (!is_array($decoded) || count($decoded) !== count($protectedValues)) {
                                    throw new \RuntimeException(sprintf(
                                          'OpenAI returned %s result(s) for %d input(s). Raw: %s',
                                          is_array($decoded) ? count($decoded) : 'null',
                                          count($protectedValues),
                                          mb_substr($raw, 0, 300)
                                    ));
                              }

                              // ── Re-map onto original keys + restore placeholders ──────
                              $mapped = [];

                              foreach ($keys as $i => $originalKey) {
                                    $normalized = $this->normalizeTranslated($decoded[$i]);
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
                        ':OpenAI'
                  );

            } catch (\Throwable $e) {
                  // Permanent failure — restore placeholders on originals so callers
                  // never receive raw __VAR_0__ tokens even in the fallback path.
                  Log::error(
                        "{$this->logTag()} Chunk failed permanently after {$this->maxRetries} attempt(s).",
                        ['error' => $e->getMessage()]
                  );

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
      // System prompt
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Build the system prompt that scopes the model's behaviour.
       *
       * DESIGN NOTES:
       *  - Temperature is already 0 in the API call; the prompt reinforces this.
       *  - Opaque placeholder tokens (__VAR_N__, __URL_N__, __HTML_N__) are listed
       *    explicitly so the model learns to recognise and preserve them.
       *  - "ONLY a valid JSON array" is critical — stray prose breaks decoding.
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