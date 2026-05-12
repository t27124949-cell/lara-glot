<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Log;
use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Tonydev\LaraGlot\Drivers\Concerns\DecodesJsonResponse;
use Tonydev\LaraGlot\Drivers\Concerns\ProtectsPlaceholders;

/**
 * Base class for all LaraGlot translation drivers.
 *
 * Provides:
 *  - A default translate() implementation that delegates to translateBatch().
 *  - withRetry(): progressive back-off retry helper.
 *  - normalizeTranslated(): consistent post-processing for all translated strings.
 *  - Both shared traits (placeholder protection + JSON decoding).
 *
 * Concrete drivers must implement translateBatch() (and optionally override translate()).
 */
abstract class AbstractTranslationDriver implements TranslationDriverInterface
{
      use ProtectsPlaceholders;
      use DecodesJsonResponse;

      // ─────────────────────────────────────────────────────────────────────────
      // Default translate()
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate a single string by delegating to translateBatch().
       *
       * Drivers with a cheaper single-string code-path (e.g. GoogleDriver) may
       * override this method; all others inherit it for free.
       */
      public function translate(string $text, string $target, string $source = 'en'): string
      {
            if (trim($text) === '') {
                  return $text;
            }

            $results = $this->translateBatch([$text], $target, $source);

            return $results[0] ?? $text;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Retry Helper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Execute $fn up to $maxAttempts times with progressive back-off.
       *
       * Each retry waits $baseDelayMs × attempt milliseconds:
       *   attempt 1 fail → wait 500 ms
       *   attempt 2 fail → wait 1 000 ms
       *   attempt 3 fail → throw
       *
       * @template T
       * @param  callable(): T $fn          The operation to attempt.
       * @param  int           $maxAttempts Maximum number of attempts (≥ 1).
       * @param  int           $baseDelayMs Base delay in milliseconds.
       * @param  string        $context     Driver label for log messages.
       * @return T
       *
       * @throws \Throwable  Re-throws the last exception when all attempts are exhausted.
       */
      protected function withRetry(
            callable $fn,
            int $maxAttempts = 3,
            int $baseDelayMs = 500,
            string $context = ''
      ): mixed {
            $lastException = null;
            $tag = $context !== '' ? "[LaraGlot{$context}]" : '[LaraGlot]';

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                  try {
                        return $fn();
                  } catch (\Throwable $e) {
                        $lastException = $e;

                        Log::warning("{$tag} Attempt {$attempt}/{$maxAttempts} failed.", [
                              'error' => $e->getMessage(),
                        ]);

                        if ($attempt < $maxAttempts) {
                              // Progressive back-off: 500 ms, 1 000 ms, 1 500 ms, …
                              usleep($baseDelayMs * $attempt * 1_000);
                        }
                  }
            }

            throw $lastException;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Normalisation Helper
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Trim whitespace, decode HTML entities, and guarantee a string type.
       * Applied to every translated string before returning it to the caller.
       *
       * @param  mixed $value  Raw value from the API / model response.
       * @return string
       */
      protected function normalizeTranslated(mixed $value): string
      {
            if (!is_string($value)) {
                  return '';
            }

            return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      }
}
