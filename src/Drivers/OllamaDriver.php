<?php

namespace Tonydev\LaraGlot\Drivers;

use Cloudstudio\Ollama\Facades\Ollama;

/**
 * Ollama driver — local LLM translation via cloudstudio/ollama-laravel.
 *
 * Self-hosted, so there is no per-call cost, but latency is higher than
 * cloud APIs: chunks are smaller (default 15), concurrency lower (default 2),
 * and the retry delay longer (default 1s). Forced JSON mode keeps parsing
 * reliable.
 *
 * Config (lara-glot.drivers.ollama.*): model, chunk_size, max_retries,
 * retry_delay_ms, concurrency, cache_enabled, cache_ttl.
 */
class OllamaDriver extends AbstractLlmDriver
{
      public function __construct()
      {
            $this->model = (string) config('lara-glot.drivers.ollama.model', 'llama3.2');
            $this->chunkSize = (int) config('lara-glot.drivers.ollama.chunk_size', 15);
            $this->maxRetries = (int) config('lara-glot.drivers.ollama.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.ollama.retry_delay_ms', 1_000);
            $this->concurrencyLimit = (int) config('lara-glot.drivers.ollama.concurrency', 2);
            $this->cacheEnabled = (bool) config('lara-glot.drivers.ollama.cache_enabled', true);
            $this->cacheTtl = (int) config('lara-glot.drivers.ollama.cache_ttl', 2_592_000);
      }

      protected function driverName(): string
      {
            return 'ollama';
      }

      protected function sendChunk(string $jsonInput, string $systemPrompt): string
      {
            $response = Ollama::model($this->model)
                  // Constrains output to valid JSON — local models drift otherwise.
                  ->format('json')
                  ->prompt("{$systemPrompt}\n\nInput:\n{$jsonInput}")
                  ->options([
                        'temperature' => 0,
                        'num_ctx' => 4096,
                  ])
                  ->ask();

            return (string) ($response['response'] ?? '');
      }
}
