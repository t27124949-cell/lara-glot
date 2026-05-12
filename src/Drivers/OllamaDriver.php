<?php

namespace Tonydev\LaraGlot\Drivers;

use Cloudstudio\Ollama\Facades\Ollama;
use Illuminate\Support\Facades\Log;

/**
 * Translation driver backed by a local Ollama instance.
 *
 * Small chunk sizes (default 10) are intentional:
 *  - Reduces hallucinations and JSON malformation.
 *  - Prevents context drift on long arrays.
 *  - Avoids OOM crashes on resource-constrained local hardware.
 *
 * Ollama runs locally so retries are cheap; a single attempt with a tight
 * temperature is usually sufficient. No API key is required.
 */
class OllamaDriver extends AbstractChunkableDriver
{
      protected string $model;

      public function __construct()
      {
            $this->model = (string) config('lara-glot.drivers.ollama.model', 'llama3');
            $this->chunkSize = (int) config('lara-glot.drivers.ollama.chunk_size', 10);
            // maxRetries kept at parent default (3); override via config if desired.
            $this->maxRetries = (int) config('lara-glot.drivers.ollama.max_retries', 3);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Core chunk translation
      // ─────────────────────────────────────────────────────────────────────────

      protected function translateChunk(array $chunk, string $target, string $source): array
      {
            $label = "LaraGlot:Ollama → [{$target}]";

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
                  $response = Ollama::model($this->model)
                        ->prompt($this->buildPrompt($source, $target, $jsonInput))
                        ->options([
                              'temperature' => 0,
                              'top_p' => 0.1,
                              'num_ctx' => 1024, // Keep context small for local translations
                              'num_thread' => 4, // Don't let it use all cores
                        ])
                        ->ask();

                  $raw = trim((string) ($response['response'] ?? ''));
                  $decoded = $this->decodeJsonResponse($raw, 'Ollama');

                  if (!is_array($decoded) || count($decoded) !== count($chunk)) {
                        throw new \RuntimeException(sprintf(
                              'Ollama returned %s result(s) for %d input(s). Raw: %s',
                              is_array($decoded) ? count($decoded) : 'null',
                              count($chunk),
                              mb_substr($raw, 0, 300)
                        ));
                  }

                  $results = array_map(
                        fn(mixed $v): string => $this->normalizeTranslated($v),
                        $decoded
                  );

                  Log::info("[{$label}] Chunk translated successfully. Cooling down...");
                  usleep(500000); // Sleep for 0.5 seconds to let the CPU spike subside

                  return $results;

            } catch (\Throwable $e) {
                  Log::error("[{$label}] Chunk failed: {$e->getMessage()}");
                  return array_values($chunk); // graceful degradation
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Prompt builder
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Build the full prompt string.
       *
       * Ollama uses a single-turn prompt rather than a system/user split,
       * so all instructions and the data are combined here.
       * Keeping the prompt short and direct reduces hallucination on smaller models.
       */
      private function buildPrompt(string $source, string $target, string $jsonInput): string
      {
            return <<<PROMPT
You are a professional translator.

Translate the JSON array below from "{$source}" to "{$target}".

Rules (non-negotiable):
- Return ONLY a valid JSON array — no markdown, no explanation, no wrapper.
- Preserve the exact element count and order.
- Keep these tokens unchanged: __HTML_N__, __URL_N__, __VAR_N__, :name, :count.
- Keep all HTML tags unchanged.
- Translate only human-readable text.

Input:
{$jsonInput}
PROMPT;
      }
}
