<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;

/**
 * Translation driver backed by the unofficial Google Translate library.
 *
 * Google has no true batch endpoint, so each string is translated
 * individually. translateBatch() loops over translate() accordingly.
 *
 * The unofficial library scrapes the public Google Translate web interface;
 * use DeepL or OpenAI for production workloads where reliability is critical.
 */
class GoogleDriver extends AbstractTranslationDriver
{
      protected GoogleTranslate $client;
      protected int $maxRetries = 3;
      protected int $retryDelayMs = 300;

      public function __construct()
      {
            $this->client = new GoogleTranslate();
            $this->maxRetries = (int) config('lara-glot.drivers.google.max_retries', 3);
            $this->retryDelayMs = (int) config('lara-glot.drivers.google.retry_delay_ms', 300);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Single translation (core path for this driver)
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate one string.
       *
       * Overrides the default AbstractTranslationDriver::translate() because
       * Google's library is string-at-a-time; routing through translateBatch()
       * would add unnecessary overhead.
       */
      public function translate(string $text, string $target, string $source = 'en'): string
      {
            if (trim($text) === '') {
                  return $text;
            }

            [$protected, $placeholders] = $this->protectPlaceholders($text);

            try {
                  $translated = $this->withRetry(
                        function () use ($protected, $source, $target): string {

                              $result = $this->client
                                    ->setSource($source)
                                    ->setTarget($target)
                                    ->translate($protected);

                              if (!is_string($result) || trim($result) === '') {
                                    throw new \RuntimeException('Google Translate returned an empty result.');
                              }

                              return $result;
                        },
                        $this->maxRetries,
                        $this->retryDelayMs,
                        ':Google'
                  );

            } catch (\Throwable $e) {
                  Log::error('[LaraGlot:Google] Translation failed permanently.', [
                        'target' => $target,
                        'error' => $e->getMessage(),
                  ]);

                  return $text; // graceful degradation
            }

            return $this->restorePlaceholders(
                  $this->normalizeTranslated($translated),
                  $placeholders
            );
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch translation (loops single translations)
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate an array of strings by calling translate() per entry.
       *
       * Non-string and empty values are passed through unchanged.
       */
      public function translateBatch(array $texts, string $target, string $source = 'en'): array
      {
            $results = [];

            foreach ($texts as $key => $text) {
                  $results[$key] = (is_string($text) && trim($text) !== '')
                        ? $this->translate($text, $target, $source)
                        : $text;
            }

            return $results;
      }
}
