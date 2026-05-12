<?php

namespace Tonydev\LaraGlot\Contracts;

interface TranslationDriverInterface
{
      /**
       * Translate a single string.
       *
       * @param  string  $text    Source text
       * @param  string  $target  Target locale (e.g. 'fr')
       * @param  string  $source  Source locale (e.g. 'en')
       * @return string           Translated text, or original on failure
       */
      public function translate(string $text, string $target, string $source = 'en'): string;

      /**
       * Translate an array of strings.
       * Keys are preserved. Empty/non-string values are passed through unchanged.
       *
       * @param  array   $texts   Flat array of strings (keys preserved)
       * @param  string  $target  Target locale
       * @param  string  $source  Source locale
       * @return array            Same keys, translated values
       */
      public function translateBatch(array $texts, string $target, string $source = 'en'): array;
}
