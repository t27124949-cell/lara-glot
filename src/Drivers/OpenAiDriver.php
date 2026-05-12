<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Translation driver backed by OpenAI-compatible chat-completion APIs.
 *
 * Compatible with OpenAI, Azure OpenAI, Groq, Together AI, and any
 * provider that exposes the /chat/completions endpoint.
 * Set `lara-glot.drivers.openai.base_url` to point at an alternative host.
 */
class OpenAiDriver extends AbstractChunkableDriver
{
      protected string $apiKey;
      protected string $model;
      protected string $baseUrl;

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

            $this->baseUrl = rtrim(
                  (string) config(
                        'lara-glot.drivers.openai.base_url',
                        'https://api.openai.com/v1'
                  ),
                  '/'
            );

            $this->chunkSize = (int) config('lara-glot.drivers.openai.chunk_size', 20);
            $this->maxRetries = (int) config('lara-glot.drivers.openai.max_retries', 3);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Core chunk translation
      // ─────────────────────────────────────────────────────────────────────────

      protected function translateChunk(array $chunk, string $target, string $source): array
      {
            $label = "LaraGlot:OpenAI → [{$target}]";

            Log::info("[{$label}] Sending chunk of " . count($chunk) . ' string(s).');

            try {
                  $jsonInput = json_encode(
                        array_values($chunk),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                  );
            } catch (\JsonException $e) {
                  Log::error("[{$label}] Failed to JSON-encode input chunk: {$e->getMessage()}");
                  return array_values($chunk);
            }

            try {
                  return $this->withRetry(
                        function () use ($chunk, $target, $source, $jsonInput, $label): array {

                              $response = Http::withToken($this->apiKey)
                                    ->timeout(90)
                                    ->post("{$this->baseUrl}/chat/completions", [
                                          'model' => $this->model,
                                          'temperature' => 0,   // deterministic output is critical for translation
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
                              $decoded = $this->decodeJsonResponse($raw, 'OpenAI');

                              if (!is_array($decoded) || count($decoded) !== count($chunk)) {
                                    throw new \RuntimeException(sprintf(
                                          'OpenAI returned %s result(s) for %d input(s). Raw: %s',
                                          is_array($decoded) ? count($decoded) : 'null',
                                          count($chunk),
                                          mb_substr($raw, 0, 300)
                                    ));
                              }

                              $results = array_map(
                                    fn(mixed $v): string => $this->normalizeTranslated($v),
                                    $decoded
                              );

                              Log::info("[{$label}] Chunk translated successfully.");

                              return $results;
                        },
                        $this->maxRetries,
                        500,
                        ':OpenAI'
                  );

            } catch (\Throwable $e) {
                  Log::error(
                        "[{$label}] Chunk failed permanently after {$this->maxRetries} attempt(s): {$e->getMessage()}"
                  );

                  return array_values($chunk); // graceful degradation
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // System prompt
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Build the system prompt instructing the model on output format and constraints.
       *
       * Explicit, tightly-scoped instructions reduce hallucination and formatting drift.
       * Placeholder token names are listed verbatim so the model learns to preserve them.
       */
      private function buildSystemPrompt(string $source, string $target): string
      {
            return <<<PROMPT
You are a professional translator. Translate the JSON array from "{$source}" to "{$target}".

RULES (non-negotiable):
- Respond with ONLY a valid JSON array — no markdown fences, no explanation, no wrapper object.
- Preserve the exact element count and order.
- Translate only visible human-readable text; do NOT translate the following tokens:
    __HTML_N__   __URL_N__   __VAR_N__   :name   :count   (and any similar patterns)
- Preserve all HTML tags exactly as written.
- Do not add, remove, or reorder elements.
PROMPT;
      }
}
