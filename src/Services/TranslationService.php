<?php

namespace Tonydev\LaraGlot\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Tonydev\LaraGlot\Drivers\AnthropicDriver;
use Tonydev\LaraGlot\Drivers\DeepLDriver;
use Tonydev\LaraGlot\Drivers\GoogleDriver;
use Tonydev\LaraGlot\Drivers\OllamaDriver;
use Tonydev\LaraGlot\Drivers\OpenAiDriver;

class TranslationService
{
      protected TranslationDriverInterface $driver;

      /**
       * User-registered driver factories, keyed by driver name.
       * Checked before the built-in map so apps can add or replace drivers
       * without forking the package.
       *
       * @var array<string, Closure(): TranslationDriverInterface>
       */
      protected static array $customDrivers = [];

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
       * Register a custom driver factory under a name usable in
       * `lara-glot.translator`. Call from a service provider's boot():
       *
       *     TranslationService::extend('my-engine', fn () => new MyEngineDriver());
       */
      public static function extend(string $name, Closure $factory): void
      {
            static::$customDrivers[$name] = $factory;
      }

      /**
       * Resolve the configured driver. Custom factories win over the built-in
       * map; built-ins resolve through the container so tests can rebind them.
       */
      protected function resolveDriver(): TranslationDriverInterface
      {
            $driverKey = (string) config('lara-glot.translator', 'google');

            if (isset(static::$customDrivers[$driverKey])) {
                  return (static::$customDrivers[$driverKey])();
            }

            $map = [
                  'anthropic' => AnthropicDriver::class,
                  'ollama' => OllamaDriver::class,
                  'openai' => OpenAiDriver::class,
                  'deepl' => DeepLDriver::class,
                  'google' => GoogleDriver::class,
            ];

            /** @var TranslationDriverInterface */
            return app()->make($map[$driverKey] ?? GoogleDriver::class);
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

            // $source is part of the hash so differing source locales never collide
            $hash = $this->makeHash($target, $normalized, $source);
            $cacheKey = $this->makeCacheKey($hash);

            // ── Local cache ───────────────────────────────────────────────────────
            if (!$force && isset($this->localCache[$hash])) {
                  return $this->localCache[$hash];
            }

            // ── Evict both layers when forcing ────────────────────────────────────
            if ($force) {
                  unset($this->localCache[$hash]);
                  Cache::forget($cacheKey);
            }

            try {
                  $result = Cache::remember(
                        $cacheKey,
                        $this->cacheTtl(),
                        function () use ($normalized, $target, $source, $force): string {
                              Log::info('[LaraGlot] Translating string.', [
                                    'target' => $target,
                                    'source' => $source,
                                    'preview' => mb_substr(strip_tags($normalized), 0, 60),
                              ]);

                              return $this->withDriverCacheBypass(
                                    $force,
                                    fn() => $this->driver->translate($normalized, $target, $source)
                              );
                        }
                  );

            } catch (\Throwable $e) {
                  Log::error('[LaraGlot] Single translation failed.', [
                        'target' => $target,
                        'source' => $source,
                        'error' => $e->getMessage(),
                  ]);

                  // The throw may have come from reading a corrupt cache entry
                  // (e.g. a bad serialized blob in a database store). Evict the
                  // key so the next attempt is a clean miss instead of failing
                  // forever until a manual cache:clear.
                  try {
                        Cache::forget($cacheKey);
                  } catch (\Throwable) {
                        // Nothing more we can do — still return the original.
                  }

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
            $needsTranslation = [];

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

                  
                  $hash = $this->makeHash($target, $normalized, $source);
                  $cacheKey = $this->makeCacheKey($hash);

                  if ($force) {
                        unset($this->localCache[$hash]);
                        Cache::forget($cacheKey);
                  }

                  if (isset($this->localCache[$hash])) {
                        $output[$key] = $this->localCache[$hash];
                        continue;
                  }

                  $cached = $this->safeCacheGet($cacheKey);

                  if ($cached !== null) {
                        $output[$key] = $cached;
                        $this->rememberLocally($hash, $cached);
                        continue;
                  }

                  $needsTranslation[$key] = $normalized;
            }

            if (empty($needsTranslation)) {
                  return $this->restoreOrder($texts, $output);
            }

            try {
                  $translated = $this->withDriverCacheBypass(
                        $force,
                        fn() => $this->driver->translateBatch($needsTranslation, $target, $source)
                  );
            } catch (\Throwable $e) {
                  Log::error('[LaraGlot] Batch translation failed.', [
                        'target' => $target,
                        'source' => $source,
                        'error' => $e->getMessage(),
                  ]);

                  // Do NOT swallow this and fall back to source text: doing so
                  // used to cache the untranslated source under the target
                  // locale for 30 days and let jobs report success on files
                  // that were 100% English. Batch callers are queue jobs and
                  // console commands — let them fail loudly and retry.
                  throw new \RuntimeException(
                        "Batch translation to [{$target}] failed: {$e->getMessage()}",
                        previous: $e
                  );
            }

            $ttl = $this->cacheTtl();

            foreach ($needsTranslation as $key => $originalValue) {
                  $translatedValue = $translated[$key] ?? $originalValue;

                  
                  $hash = $this->makeHash($target, $originalValue, $source);
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
       * Return the trimmed string, or null if it contains no visible translatable text.
       * Strings of pure HTML with no visible content (e.g. "<br>") are skipped.
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
       * Collision-resistant hash for a (source, target, text) triple.
       *
       * $source is included so translating 'fr' → 'de' and 'en' → 'de'
       * for the same text produce different hashes — no wrong cache hit.
       */
      protected function makeHash(string $target, string $text, string $source = 'en'): string
      {
            return md5("{$source}|{$target}|{$text}");
      }

      protected function makeCacheKey(string $hash): string
      {
            return "lara-glot.translation.{$hash}";
      }

      /**
       * Run a driver call with the driver's OWN string-level cache disabled
       * when forcing. Drivers keep a second cache layer under their own keys;
       * without this, a forced refresh (repair, laraglot:retry) evicts only
       * this service's layer and the driver re-serves the stale — possibly
       * poisoned — cached value it was asked to re-translate.
       *
       * @template T
       * @param  callable(): T $call
       * @return T
       */
      protected function withDriverCacheBypass(bool $force, callable $call): mixed
      {
            if (!$force || !method_exists($this->driver, 'setCacheEnabled')) {
                  return $call();
            }

            $previous = method_exists($this->driver, 'isCacheEnabled')
                  ? $this->driver->isCacheEnabled()
                  : true;

            $this->driver->setCacheEnabled(false);

            try {
                  return $call();
            } finally {
                  $this->driver->setCacheEnabled($previous);
            }
      }

      /**
       * Read a key from the cache, treating an unreadable (corrupt) entry as a
       * miss and evicting it so it cannot keep failing until a manual
       * cache:clear.
       */
      protected function safeCacheGet(string $key): mixed
      {
            try {
                  return Cache::get($key);
            } catch (\Throwable $e) {
                  Log::warning('[LaraGlot] Evicting unreadable cache entry.', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                  ]);

                  try {
                        Cache::forget($key);
                  } catch (\Throwable) {
                        // Store refused the delete — still treat as a miss.
                  }

                  return null;
            }
      }

      /**
       * Store a result in the in-process cache, pruning the oldest half of
       * entries once the cap is reached so memory stays bounded.
       */
      protected function rememberLocally(string $hash, string $value): void
      {
            if (count($this->localCache) >= $this->maxLocalCacheItems) {
                  $this->localCache = array_slice(
                        $this->localCache,
                        (int) ($this->maxLocalCacheItems / 2),
                        preserve_keys: true
                  );
            }

            $this->localCache[$hash] = $value;
      }

      /**
       * Return the translated array in the same key order as the original input.
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
       * Cache TTL as a DateTimeInterface, read from config.
       * Default: 30 days (2 592 000 seconds).
       */
      protected function cacheTtl(): \DateTimeInterface
      {
            return now()->addSeconds(
                  (int) config('lara-glot.cache_expiry', 2_592_000)
            );
      }
}
