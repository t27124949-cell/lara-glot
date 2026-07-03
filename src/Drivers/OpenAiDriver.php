<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible chat-completion driver.
 *
 * Works with any provider exposing the /chat/completions endpoint: OpenAI,
 * Azure OpenAI, Groq, Together AI, or a local Ollama server via its
 * OpenAI-compatible REST layer. Point `base_url` at the provider of choice.
 *
 * Config (lara-glot.drivers.openai.*): api_key, model, base_url, chunk_size,
 * max_retries, retry_delay_ms, concurrency, cache_enabled, cache_ttl.
 */
class OpenAiDriver extends AbstractLlmDriver
{
      protected string $logName = 'OpenAI';

      protected string $apiKey;

      protected string $baseUrl;

      public function __construct()
      {
            $this->apiKey = (string) config('lara-glot.drivers.openai.api_key', '');
            $this->model = (string) config('lara-glot.drivers.openai.model', 'gpt-5-mini');
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

      protected function driverName(): string
      {
            return 'openai';
      }

      protected function sendChunk(string $jsonInput, string $systemPrompt): string
      {
            $response = Http::withToken($this->apiKey)
                  ->timeout(90)
                  ->post("{$this->baseUrl}/chat/completions", [
                        'model' => $this->model,
                        'messages' => [
                              ['role' => 'system', 'content' => $systemPrompt],
                              ['role' => 'user', 'content' => $jsonInput],
                        ],
                  ]);

            if (!$response->successful()) {
                  throw new \RuntimeException(
                        "OpenAI HTTP {$response->status()}: {$response->body()}"
                  );
            }

            return (string) $response->json('choices.0.message.content', '');
      }
}
