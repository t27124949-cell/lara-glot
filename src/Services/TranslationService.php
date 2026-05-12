<?php

namespace Tonydev\LaraGlot\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Tonydev\LaraGlot\Drivers\DeepLDriver;
use Tonydev\LaraGlot\Drivers\GoogleDriver;
use Tonydev\LaraGlot\Drivers\OllamaDriver;
use Tonydev\LaraGlot\Drivers\OpenAiDriver;

class TranslationService
{
      protected TranslationDriverInterface $driver;

      /** In-process string → translation map; avoids redundant cache reads. */
      protected array $localCache = [];

      /** Maximum entries held in $localCache before the oldest half is pruned. */
      protected int $maxLocalCacheItems = 1000;

      public function __construct()
      {
            $this->driver = $this->resolveDriver();
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Driver Resolution
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Resolve the configured driver through Laravel's service container so that
       * consumers can rebind drivers in tests or extend them via the app container.
       */
      protected function resolveDriver(): TranslationDriverInterface
      {
            $map = [
                  'ollama' => OllamaDriver::class,
                  'openai' => OpenAiDriver::class,
                  'deepl' => DeepLDriver::class,
                  'google' => GoogleDriver::class,
            ];

            $driverKey = (string) config('lara-glot.translator', 'google');
            $driverClass = $map[$driverKey] ?? GoogleDriver::class;

            /** @var TranslationDriverInterface */
            return app()->make($driverClass);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Single Translation
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate one string, serving from the nearest available cache layer.
       *
       * Cache hierarchy:
       *   1. In-process local cache  (fastest; lasts for the current request/job)
       *   2. Laravel cache store     (shared across processes; configurable TTL)
       *   3. Driver API call         (only when neither cache layer has a result)
       *
       * @param  string  $text    Source string.
       * @param  string  $target  Target locale code (e.g. 'fr', 'es').
       * @param  string  $source  Source locale code (default 'en').
       * @param  bool    $force   When true, bypass and refresh both cache layers.
       * @return string           Translated string, or original on failure.
       */
      public function translate(
            string $text,
            string $target,
            string $source = 'en',
            bool $force = false
      ): string {
            $normalized = $this->normalizeText($text);

            if ($normalized === null) {
                  return $text;
            }

            $hash = $this->makeHash($target, $normalized);
            $cacheKey = $this->makeCacheKey($hash);

            // ── Local cache ───────────────────────────────────────────────────────
            if (!$force && isset($this->localCache[$hash])) {
                  return $this->localCache[$hash];
            }

            // ── Persistent cache ──────────────────────────────────────────────────
            if ($force) {
                  // Evict both layers so the driver is always called.
                  unset($this->localCache[$hash]);
                  Cache::forget($cacheKey);
            }

            try {
                  $result = Cache::remember(
                        $cacheKey,
                        $this->cacheTtl(),
                        function () use ($normalized, $target, $source): string {

                              Log::info('[LaraGlot] Translating string.', [
                                    'target' => $target,
                                    'source' => $source,
                                    'preview' => mb_substr(strip_tags($normalized), 0, 60),
                              ]);

                              // Drivers already normalise their output (html_entity_decode + trim).
                              return $this->driver->translate($normalized, $target, $source);
                        }
                  );

            } catch (\Throwable $e) {
                  Log::error('[LaraGlot] Single translation failed.', [
                        'target' => $target,
                        'source' => $source,
                        'error' => $e->getMessage(),
                  ]);

                  return $text;
            }

            $final = ($result !== '' && $result !== null) ? $result : $text;

            $this->rememberLocally($hash, $final);

            return $final;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch Translation
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate an array of strings in one driver call.
       *
       * Strings already present in either cache layer are served immediately;
       * only uncached strings are forwarded to the driver, minimising API cost.
       *
       * @param  array<int|string, mixed>  $texts   Source strings (keys preserved).
       * @param  string                    $target  Target locale code.
       * @param  string                    $source  Source locale code.
       * @param  bool                      $force   Bypass and refresh all caches.
       * @return array<int|string, mixed>           Translated strings, original order restored.
       */
      public function translateBatch(
            array $texts,
            string $target,
            string $source = 'en',
            bool $force = false
      ): array {
            $output = [];
            $needsTranslation = [];   // key → normalised source string for uncached entries

            foreach ($texts as $key => $value) {
                  if (!is_string($value)) {
                        $output[$key] = $value;
                        continue;
                  }

                  $normalized = $this->normalizeText($value);

                  if ($normalized === null) {
                        $output[$key] = $value;
                        continue;
                  }

                  $hash = $this->makeHash($target, $normalized);
                  $cacheKey = $this->makeCacheKey($hash);

                  // ── Evict if forcing ──────────────────────────────────────────────
                  if ($force) {
                        unset($this->localCache[$hash]);
                        Cache::forget($cacheKey);
                  }

                  // ── Local cache hit ───────────────────────────────────────────────
                  if (isset($this->localCache[$hash])) {
                        $output[$key] = $this->localCache[$hash];
                        continue;
                  }

                  // ── Persistent cache hit ──────────────────────────────────────────
                  $cached = Cache::get($cacheKey);

                  if ($cached !== null) {
                        $output[$key] = $cached;
                        $this->rememberLocally($hash, $cached);
                        continue;
                  }

                  // ── Needs driver call ─────────────────────────────────────────────
                  $needsTranslation[$key] = $normalized;
            }

            if (empty($needsTranslation)) {
                  return $this->restoreOrder($texts, $output);
            }

            // ── Single driver call for all uncached strings ───────────────────────
            try {
                  $translated = $this->driver->translateBatch($needsTranslation, $target, $source);
            } catch (\Throwable $e) {
                  Log::error('[LaraGlot] Batch translation failed.', [
                        'target' => $target,
                        'source' => $source,
                        'error' => $e->getMessage(),
                  ]);

                  $translated = $needsTranslation; // graceful degradation: return originals
            }

            // ── Persist results and populate output ───────────────────────────────
            $ttl = $this->cacheTtl();

            foreach ($needsTranslation as $key => $originalValue) {
                  $translatedValue = $translated[$key] ?? $originalValue;

                  $hash = $this->makeHash($target, $originalValue);
                  $cacheKey = $this->makeCacheKey($hash);

                  Cache::put($cacheKey, $translatedValue, $ttl);
                  $this->rememberLocally($hash, $translatedValue);

                  $output[$key] = $translatedValue;
            }

            return $this->restoreOrder($texts, $output);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Helpers
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Return the trimmed string, or null if it contains no translatable content.
       * A string of pure HTML tags with no visible text (e.g. "<br>") is skipped.
       */
      protected function normalizeText(string $text): ?string
      {
            $trimmed = trim($text);

            if ($trimmed === '' || trim(strip_tags($trimmed)) === '') {
                  return null;
            }

            return $trimmed;
      }

      /**
       * Stable, collision-resistant hash for a (locale, text) pair.
       * xxhash would be faster but md5 requires no extension.
       */
      protected function makeHash(string $target, string $text): string
      {
            return md5("{$target}|{$text}");
      }

      protected function makeCacheKey(string $hash): string
      {
            return "lara-glot.translation.{$hash}";
      }

      /**
       * Store a result in the in-process cache, pruning the oldest half of
       * entries once the cap is reached so memory stays bounded.
       */
      protected function rememberLocally(string $hash, string $value): void
      {
            if (count($this->localCache) >= $this->maxLocalCacheItems) {
                  // Drop the oldest half; array_slice preserves insertion order.
                  $this->localCache = array_slice(
                        $this->localCache,
                        (int) ($this->maxLocalCacheItems / 2),
                        preserve_keys: true
                  );
            }

            $this->localCache[$hash] = $value;
      }

      /**
       * Return the translated array with keys in the same order as the input.
       * Entries missing from $translated fall back to their original values.
       */
      protected function restoreOrder(array $original, array $translated): array
      {
            $ordered = [];

            foreach ($original as $key => $value) {
                  $ordered[$key] = $translated[$key] ?? $value;
            }

            return $ordered;
      }

      /**
       * Cache TTL as a DateTimeInterface, read once from config.
       * Default: 30 days (2 592 000 seconds).
       */
      protected function cacheTtl(): \DateTimeInterface
      {
            return now()->addSeconds(
                  (int) config('lara-glot.cache_expiry', 2_592_000)
            );
      }
}