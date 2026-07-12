<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tonydev\LaraGlot\Concerns\TransportsConcurrencyResults;
use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Tonydev\LaraGlot\Drivers\Concerns\DecodesJsonResponse;
use Tonydev\LaraGlot\Drivers\Concerns\ProtectsPlaceholders;

abstract class AbstractTranslationDriver implements TranslationDriverInterface
{
      use ProtectsPlaceholders;
      use DecodesJsonResponse;
      use TransportsConcurrencyResults;

      // ─────────────────────────────────────────────────────────────────────────
      // Shared config properties
      // ─────────────────────────────────────────────────────────────────────────

      protected int $maxRetries = 3;
      protected int $retryDelayMs = 500;
      protected int $concurrencyLimit = 5;
      protected bool $cacheEnabled = true;
      protected int $cacheTtl = 2_592_000;

      // ─────────────────────────────────────────────────────────────────────────
      // Runtime stats
      // ─────────────────────────────────────────────────────────────────────────

      private int $cacheHits = 0;
      private int $cacheMisses = 0;
      private int $apiCalls = 0;

      // ─────────────────────────────────────────────────────────────────────────
      // Abstract contract
      // ─────────────────────────────────────────────────────────────────────────

      abstract protected function driverName(): string;

      /**
       * @param  array<int|string, string> $texts
       * @return array<int|string, string>
       */
      abstract public function translateBatch(
            array $texts,
            string $target,
            string $source = 'en'
      ): array;

      // ─────────────────────────────────────────────────────────────────────────
      // Default translate() — delegates to translateBatch()
      // ─────────────────────────────────────────────────────────────────────────

      public function translate(string $text, string $target, string $source = 'en'): string
      {
            if (trim($text) === '') {
                  return $text;
            }

            $results = $this->translateBatch([$text], $target, $source);

            return $results[0] ?? $text;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Public utility methods
      // ─────────────────────────────────────────────────────────────────────────

      public function warmUp(array $texts, string $target, string $source = 'en'): array
      {
            return $this->translateBatch($texts, $target, $source);
      }

      public function setCacheEnabled(bool $enabled): static
      {
            $this->cacheEnabled = $enabled;

            return $this;
      }

      public function isCacheEnabled(): bool
      {
            return $this->cacheEnabled;
      }

      public function isCached(string $text, string $target, string $source = 'en'): bool
      {
            if (!$this->cacheEnabled) {
                  return false;
            }

            return Cache::has($this->getCacheKey($text, $target, $source));
      }

      /**
       * @return array{cache_hits: int, cache_misses: int, api_calls: int, hit_rate: float}
       */
      public function getStats(): array
      {
            $total = $this->cacheHits + $this->cacheMisses;

            return [
                  'cache_hits' => $this->cacheHits,
                  'cache_misses' => $this->cacheMisses,
                  'api_calls' => $this->apiCalls,
                  'hit_rate' => $total > 0
                        ? round($this->cacheHits / $total, 4)
                        : 0.0,
            ];
      }

      public function resetStats(): void
      {
            $this->cacheHits = 0;
            $this->cacheMisses = 0;
            $this->apiCalls = 0;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Cache helpers
      // ─────────────────────────────────────────────────────────────────────────

      protected function getCacheKey(string $text, string $target, string $source): string
      {
            return sprintf(
                  'laraglot:%s:%s:%s:%s',
                  $this->driverName(),
                  $source,
                  $target,
                  hash('sha256', $text)
            );
      }

      protected function getFromCache(string $key): ?string
      {
            if (!$this->cacheEnabled) {
                  return null;
            }

            try {
                  $value = Cache::get($key);
            } catch (\Throwable $e) {
                  // A corrupt entry (e.g. a bad serialized blob in a database
                  // store) must read as a miss, not a permanent failure. Evict
                  // it so the key can be rewritten instead of throwing forever.
                  Log::warning('[LaraGlot] Evicting unreadable cache entry.', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                  ]);

                  try {
                        Cache::forget($key);
                  } catch (\Throwable) {
                        // The store may refuse the delete too — still a miss.
                  }

                  $value = null;
            }

            if (is_string($value)) {
                  $this->cacheHits++;
                  return $value;
            }

            $this->cacheMisses++;

            return null;
      }

      protected function putInCache(string $key, string $value): void
      {
            if ($this->cacheEnabled) {
                  Cache::put($key, $value, $this->cacheTtl);
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Concurrency helper
      // ─────────────────────────────────────────────────────────────────────────
      //
      // runConcurrentBatches() lives in the TransportsConcurrencyResults trait,
      // shared with SmartTranslationService: results cross the process boundary
      // base64-encoded so multibyte payloads survive the `process` driver.

      // ─────────────────────────────────────────────────────────────────────────
      // Retry helper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * @template T
       * @param  callable(): T $fn
       * @return T
       * @throws \Throwable
       */
      protected function withRetry(
            callable $fn,
            int $maxAttempts = 3,
            int $baseDelayMs = 500,
            string $context = ''
      ): mixed {
            $lastException = null;

            $tag = $context !== ''
                  ? "[LaraGlot{$context}]"
                  : '[LaraGlot]';

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                  try {
                        return $fn();
                  } catch (\Throwable $e) {
                        $lastException = $e;

                        Log::warning("{$tag} Attempt {$attempt}/{$maxAttempts} failed.", [
                              'error' => $e->getMessage(),
                        ]);

                        if ($attempt < $maxAttempts) {
                              // Exponential back-off: base, 2×, 4×, 8× … — repeated 500s
                              // from flaky endpoints need growing gaps, not fixed ones.
                              // usleep() takes microseconds → ms × 1_000.
                              usleep($baseDelayMs * (2 ** ($attempt - 1)) * 1_000);
                        }
                  }
            }

            throw $lastException;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Normalisation helper
      // ─────────────────────────────────────────────────────────────────────────

      protected function normalizeTranslated(mixed $value): string
      {
            if (!is_string($value)) {
                  return '';
            }

            return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Log tag helper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Drivers may override $logName for non-ucfirst names (e.g. 'OpenAI', 'DeepL').
       */
      protected string $logName = '';

      protected function logTag(): string
      {
            $name = $this->logName ?: ucfirst($this->driverName());

            return "[LaraGlot:{$name}]";
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Internal counter helper
      // ─────────────────────────────────────────────────────────────────────────

      protected function recordApiCall(): void
      {
            $this->apiCalls++;
      }
}
