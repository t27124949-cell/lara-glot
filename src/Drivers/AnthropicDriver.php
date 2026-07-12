<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Http;

/**
 * Anthropic Claude driver — native Messages API.
 *
 * Claude models are particularly strong at translation nuance and register.
 * The default is claude-haiku-4-5 for high-volume work at low cost; set
 * `model` to claude-sonnet-5 when maximum quality matters more than price.
 *
 * No sampling parameters are sent: current Claude models reject temperature
 * overrides, and the deterministic-output instruction lives in the prompt.
 *
 * Config (lara-glot.drivers.anthropic.*): api_key, model, base_url,
 * max_tokens, chunk_size, max_retries, retry_delay_ms, concurrency,
 * cache_enabled, cache_ttl.
 */
class AnthropicDriver extends AbstractLlmDriver
{
      protected string $logName = 'Anthropic';

      protected string $apiKey;

      protected string $baseUrl;

      /** Required by the Messages API — caps output length per chunk call. */
      protected int $maxTokens;

      /** Per-request HTTP timeout in seconds. */
      protected int $timeout;

      public function __construct()
      {
            $this->timeout = (int) config('lara-glot.drivers.anthropic.timeout', 120);
            $this->apiKey = (string) config('lara-glot.drivers.anthropic.api_key', '');
            $this->model = (string) config('lara-glot.drivers.anthropic.model', 'claude-haiku-4-5');
            $this->baseUrl = rtrim(
                  (string) config('lara-glot.drivers.anthropic.base_url', 'https://api.anthropic.com'),
                  '/'
            );
            $this->maxTokens = (int) config('lara-glot.drivers.anthropic.max_tokens', 8_192);
            $this->chunkSize = (int) config('lara-glot.drivers.anthropic.chunk_size', 30);
            $this->maxRetries = (int) config('lara-glot.drivers.anthropic.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.anthropic.retry_delay_ms', 500);
            $this->concurrencyLimit = (int) config('lara-glot.drivers.anthropic.concurrency', 3);
            $this->cacheEnabled = (bool) config('lara-glot.drivers.anthropic.cache_enabled', true);
            $this->cacheTtl = (int) config('lara-glot.drivers.anthropic.cache_ttl', 2_592_000);
      }

      protected function driverName(): string
      {
            return 'anthropic';
      }

      protected function sendChunk(string $jsonInput, string $systemPrompt): string
      {
            $response = Http::withHeaders([
                  'x-api-key' => $this->apiKey,
                  'anthropic-version' => '2023-06-01',
            ])
                  ->timeout($this->timeout)
                  ->post("{$this->baseUrl}/v1/messages", [
                        'model' => $this->model,
                        'max_tokens' => $this->maxTokens,
                        'system' => $systemPrompt,
                        'messages' => [
                              ['role' => 'user', 'content' => $jsonInput],
                        ],
                  ]);

            if (!$response->successful()) {
                  throw new \RuntimeException(
                        "Anthropic HTTP {$response->status()}: {$response->body()}"
                  );
            }

            // Content is a list of typed blocks; translation output is the
            // first text block. Anything else (e.g. a refusal) is an error
            // the retry/fallback path should handle.
            foreach ((array) $response->json('content', []) as $block) {
                  if (($block['type'] ?? '') === 'text') {
                        return (string) $block['text'];
                  }
            }

            throw new \RuntimeException(
                  'Anthropic response contained no text block. Stop reason: '
                        . (string) $response->json('stop_reason', 'unknown')
            );
      }
}
