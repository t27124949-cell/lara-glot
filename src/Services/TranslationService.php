<?php

namespace Tonydev\LaraGlot\Services;

use Stichoza\GoogleTranslate\GoogleTranslate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranslationService
{
      protected GoogleTranslate $translator;

      /**
       * In-memory cache (per request) to avoid hitting Redis/File cache 
       * multiple times for the same string in a single loop.
       */
      protected array $localCache = [];

      public function __construct()
      {
            $this->translator = new GoogleTranslate();

            // Use the package config for the source language
            $this->translator->setSource(config('lara-glot.source_locale', 'en'));
      }

      /**
       * Translate a single string with multi-layer caching.
       */
      public function translate(string $text, string $target, bool $force = false): string
      {
            $trimmedText = trim($text);

            // Skip translation for empty strings or purely HTML tags
            if ($trimmedText === '' || trim(strip_tags($trimmedText)) === '') {
                  return $text;
            }

            // Unique hash per target language and text content
            $hash = md5($target . '|' . $trimmedText);

            /**
             * 1. Layer One: RAM Cache (fastest, current request only)
             */
            if (!$force && isset($this->localCache[$hash])) {
                  return $this->localCache[$hash];
            }

            $cacheKey = "lara-glot.translation.$hash";

            /**
             * 2. Layer Two: Force refresh (busts persistent cache)
             */
            if ($force) {
                  Cache::forget($cacheKey);
            }

            /**
             * 3. Layer Three: Persistent Cache (Defaults to 30 days via config)
             */
            $result = Cache::remember(
                  $cacheKey,
                  now()->addSeconds(config('lara-glot.cache_expiry', 2592000)),
                  function () use ($trimmedText, $target) {
                        try {
                              Log::info("🌍 [LaraGlot] Translating [$target]: " . substr(strip_tags($trimmedText), 0, 60));

                              $translated = $this->translator
                                    ->setTarget($target)
                                    ->translate($trimmedText);

                              // Decode HTML entities (e.g., &quot; back to ")
                              return html_entity_decode($translated, ENT_QUOTES, 'UTF-8');

                        } catch (\Throwable $e) {
                              Log::error("❌ [LaraGlot] API Error [$target]: " . $e->getMessage());
                              return null;
                        }
                  }
            );

            /**
             * 4. Fallback: If API fails, return the original text
             */
            $finalValue = $result ?? $text;

            /**
             * 5. Store in RAM cache for the remainder of this execution
             */
            $this->localCache[$hash] = $finalValue;

            return $finalValue;
      }
}