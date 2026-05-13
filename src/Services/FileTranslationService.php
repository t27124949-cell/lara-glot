<?php

namespace Tonydev\LaraGlot\Services;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FileTranslationService
{
      /**
       * Translation service implementation.
       */
      protected TranslationService $translator;

      /**
       * Source locale configured in lara-glot.php.
       */
      protected string $sourceLocale;

      /**
       * Files excluded from translation scanning.
       */
      protected array $excludedFiles;

      public function __construct(TranslationService $translator)
      {
            $this->translator = $translator;

            // Centralized config loading
            $this->sourceLocale = config(
                  'lara-glot.source_locale',
                  'en'
            );

            $this->excludedFiles = config(
                  'lara-glot.exclude_files',
                  []
            );
      }

      /**
       * Translate an entire language file.
       *
       * Used by:
       * - CLI commands
       * - Queue jobs
       * - Automated sync workflows
       *
       * Example:
       * auth → lang/fr/auth.php
       */
      public function translateFile(
            string $fileName,
            string $targetLocale
      ): void {
            $sourcePath = $this->getLanguageFilePath(
                  $this->sourceLocale,
                  $fileName
            );

            if (!File::exists($sourcePath)) {

                  Log::error(
                        '[LaraGlot] Source file not found.',
                        ['path' => $sourcePath]
                  );

                  throw new Exception(
                        "Source file [{$this->sourceLocale}/{$fileName}.php] not found."
                  );
            }

            /**
             * Use Laravel-safe require helper.
             *
             * This is safer than raw include and
             * matches Laravel internals.
             */
            $translations = File::getRequire($sourcePath);

            if (!is_array($translations)) {
                  return;
            }

            /**
             * Flatten nested translation arrays:
             *
             * [
             *   'auth' => [
             *      'failed' => '...'
             *   ]
             * ]
             *
             * becomes:
             *
             * auth.failed => ...
             */
            $flatArray = Arr::dot($translations);

            $translatedFlat = $this->translateBatch(
                  $flatArray,
                  $targetLocale
            );

            /**
             * Restore nested array structure.
             */
            $translatedNested = [];

            foreach ($translatedFlat as $key => $value) {
                  Arr::set($translatedNested, $key, $value);
            }

            $this->saveToFile(
                  $fileName,
                  $targetLocale,
                  $translatedNested
            );
      }

      /**
       * Translate a flat translation array.
       *
       * Used by:
       * - Preview system
       * - Batch jobs
       * - File translation
       */
      public function translateBatch(
            array $flatArray,
            string $locale
      ): array {
            $keys = array_keys($flatArray);

            /**
             * Protect Laravel placeholders:
             * :name
             * :count
             * etc.
             */
            $maskedValues = array_map(
                  function ($text) {
                        return is_string($text)
                              ? $this->maskPlaceholders($text)
                              : $text;
                  },
                  array_values($flatArray)
            );

            /**
             * Send translation batch to provider.
             */
            $translatedValues = $this->translator->translateBatch(
                  $maskedValues,
                  $locale,
                  $this->sourceLocale
            );

            /**
             * Restore placeholders after translation.
             */
            $finalValues = array_map(
                  fn($text) => is_string($text)
                  ? $this->restorePlaceholders($text)
                  : $text,
                  $translatedValues
            );

            return array_combine($keys, $finalValues);
      }

      /**
       * Save translated language array to disk.
       *
       * Supports:
       * - root files
       * - nested directories
       *
       * Example:
       * admin/users
       */
      public function saveToFile(
            string $fileName,
            string $locale,
            array $data
      ): void {
            $path = $this->getLanguageFilePath(
                  $locale,
                  $fileName
            );

            $directory = dirname($path);

            /**
             * Create nested directories automatically.
             */
            if (!File::exists($directory)) {
                  File::makeDirectory(
                        $directory,
                        0755,
                        true
                  );
            }

            /**
             * Convert PHP array to short syntax.
             */
            $export = var_export($data, true);

            $export = preg_replace(
                  '/^([ ]*)(.*)/m',
                  '$1$1$2',
                  $export
            );

            $array = str_replace(
                  ['array (', ')'],
                  ['[', ']'],
                  $export
            );

            $content = "<?php\n\nreturn {$array};\n";

            File::put($path, $content);
      }

      /**
       * Get all translatable language files.
       *
       * Supports:
       * - root files
       * - nested files
       * - recursive scanning
       *
       * Examples:
       * auth
       * validation
       * admin/users
       */
      public function getTranslatableFiles(): array
      {
            $directory = lang_path($this->sourceLocale);

            if (!File::exists($directory)) {
                  return [];
            }

            return collect(File::allFiles($directory))

                  /**
                   * Only PHP language files.
                   */
                  ->filter(
                        fn($file) => $file->getExtension() === 'php'
                  )

                  /**
                   * Normalize paths.
                   */
                  ->mapWithKeys(function ($file) use ($directory) {

                        $relative = str_replace(
                              [
                                    $directory . DIRECTORY_SEPARATOR,
                                    '.php',
                              ],
                              '',
                              $file->getPathname()
                        );

                        /**
                         * Windows/Linux compatibility.
                         */
                        $relative = str_replace(
                              DIRECTORY_SEPARATOR,
                              '/',
                              $relative
                        );

                        /**
                         * Skip excluded files.
                         */
                        if (
                              in_array(
                                    $relative,
                                    $this->excludedFiles,
                                    true
                              )
                        ) {
                              return [];
                        }

                        return [
                              $relative => $relative,
                        ];
                  })

                  ->sortKeys()

                  ->toArray();
      }

      /**
       * Generate a language file path.
       *
       * Examples:
       * lang/en/auth.php
       * lang/fr/admin/users.php
       */
      protected function getLanguageFilePath(
            string $locale,
            string $fileName
      ): string {
            return lang_path(
                  "{$locale}/{$fileName}.php"
            );
      }

      /**
       * Protect placeholders before translation.
       *
       * Prevents translation providers from modifying:
       * :name
       * :count
       * etc.
       */
      protected function maskPlaceholders(
            string $text
      ): string {

            /**
             * Handle Laravel pluralization strings.
             *
             * Example:
             * one|many
             */
            if (str_contains($text, '|')) {

                  $parts = explode('|', $text);

                  return implode(
                        '|',
                        array_map(
                              fn($part) => $this->maskPlaceholders(
                                    trim($part)
                              ),
                              $parts
                        )
                  );
            }

            return preg_replace(
                  '/:([a-zA-Z0-9_]+)/',
                  '<span translate="no">:$1</span>',
                  $text
            );
      }

      /**
       * Restore placeholders after translation.
       */
      protected function restorePlaceholders(
            string $translatedText
      ): string {
            $text = html_entity_decode(
                  $translatedText,
                  ENT_QUOTES,
                  'UTF-8'
            );

            /**
             * Remove injected protection tags.
             */
            $text = preg_replace(
                  '/<span\b[^>]*>/i',
                  '',
                  $text
            );

            $text = str_ireplace(
                  '</span>',
                  '',
                  $text
            );

            /**
             * Fix spacing issues:
             *
             * "Hello :name !"
             * → "Hello :name!"
             */
            $text = preg_replace(
                  '/\s?(:[a-zA-Z0-9_]+)\s?/',
                  ' $1',
                  $text
            );

            return trim($text);
      }
}