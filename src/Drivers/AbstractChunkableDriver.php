<?php

namespace Tonydev\LaraGlot\Drivers;

/**
 * Extends AbstractTranslationDriver with the chunk-based batch translation
 * strategy used by DeepL, OpenAI, and Ollama.
 *
 * Responsibilities handled here so concrete drivers only implement translateChunk():
 *  1. Protect placeholders in every input string.
 *  2. Split the work into configurable-size chunks.
 *  3. Feed each chunk to translateChunk() (abstract; API-specific).
 *  4. Stitch results back together and restore placeholders.
 *  5. Fall back to the original string whenever a position is missing.
 */
abstract class AbstractChunkableDriver extends AbstractTranslationDriver
{
      /** Maximum number of strings sent to the API in a single request. */
      protected int $chunkSize = 20;

      /** How many times to attempt a failed chunk before giving up. */
      protected int $maxRetries = 3;

      // ─────────────────────────────────────────────────────────────────────────
      // translateBatch()
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * {@inheritdoc}
       *
       * Template-method implementation:
       *  - Skips empty / non-string values (preserves them as-is).
       *  - Protects placeholders before translation.
       *  - Restores placeholders after translation.
       *  - Falls back to the original string on partial API failure.
       */
      public function translateBatch(array $texts, string $target, string $source = 'en'): array
      {
            // Start with a copy so skipped entries (non-strings, empty) are preserved.
            $output = $texts;
            $toTranslate = [];    // protected strings in position order
            $keyMap = [];    // position → original array key
            $placeholderMap = [];    // original key → placeholder map

            foreach ($texts as $key => $value) {
                  if (!is_string($value) || trim($value) === '') {
                        continue;
                  }

                  [$protected, $placeholders] = $this->protectPlaceholders($value);

                  $keyMap[] = $key;
                  $toTranslate[] = $protected;
                  $placeholderMap[$key] = $placeholders;
            }

            if (empty($toTranslate)) {
                  return $output;
            }

            // ── Chunk, translate, flatten ─────────────────────────────────────────
            $translatedFlat = [];

            foreach (array_chunk($toTranslate, $this->chunkSize) as $chunk) {
                  foreach ($this->translateChunk($chunk, $target, $source) as $translated) {
                        $translatedFlat[] = $translated;
                  }
            }

            // ── Restore keys and placeholders ─────────────────────────────────────
            foreach ($keyMap as $position => $key) {
                  // If a chunk returned fewer results than expected, fall back gracefully.
                  $translated = $translatedFlat[$position] ?? $texts[$key];
                  $output[$key] = $this->restorePlaceholders(
                        $translated,
                        $placeholderMap[$key] ?? []
                  );
            }

            return $output;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // translateChunk() — implement in each concrete driver
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Send one chunk of pre-protected strings to the translation backend.
       *
       * Contract:
       *  - Input:  $chunk is a 0-indexed array of strings with placeholders already applied.
       *  - Output: must be a 0-indexed array of the same length, in the same order.
       *  - On unrecoverable failure: return array_values($chunk) (original strings).
       *    Never throw — translateBatch() relies on graceful degradation.
       *
       * @param  list<string> $chunk   Strings to translate.
       * @param  string       $target  Target locale code.
       * @param  string       $source  Source locale code.
       * @return list<string>
       */
      abstract protected function translateChunk(
            array $chunk,
            string $target,
            string $source
      ): array;
}
