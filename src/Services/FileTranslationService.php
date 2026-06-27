<?php

namespace Tonydev\LaraGlot\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tonydev\LaraGlot\Services\TranslationService;
class FileTranslationService
{
      /**
       * The translation service — provides in-process caching, force-refresh,
       * and text normalisation on top of the raw driver.
       */
      protected TranslationService $translator;

      /** Source locale configured in lara-glot.php. */
      protected string $sourceLocale;

      /** Files excluded from translation scanning. */
      protected array $excludedFiles;

      public function __construct(TranslationService $translator)
      {
            $this->translator = $translator;
            $this->sourceLocale = config('lara-glot.source_locale', 'en');
            $this->excludedFiles = config('lara-glot.exclude_files', []);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Public API
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate an entire language file and write it to disk.
       *
       * Used by CLI commands, queue jobs, and automated sync workflows.
       * Example: 'auth' → lang/fr/auth.php
       */
      public function translateFile(string $fileName, string $targetLocale): void
      {
            $sourcePath = $this->getLanguageFilePath($this->sourceLocale, $fileName);

            if (!File::exists($sourcePath)) {
                  Log::error('[LaraGlot] Source file not found.', ['path' => $sourcePath]);

                  throw new \RuntimeException(
                        "Source file [{$this->sourceLocale}/{$fileName}.php] not found."
                  );
            }

            $translations = File::getRequire($sourcePath);

            if (!is_array($translations)) {
                  return;
            }

            // Flatten nested array: ['auth' => ['failed' => '...']] → ['auth.failed' => '...']
            $flatArray = Arr::dot($translations);

            $translatedFlat = $this->translateBatch($flatArray, $targetLocale);

            // Restore nested structure from dot-notation keys.
            $translatedNested = [];
            foreach ($translatedFlat as $key => $value) {
                  Arr::set($translatedNested, $key, $value);
            }

            $this->saveToFile($fileName, $targetLocale, $translatedNested);
      }

      /**
       * Translate a flat dot-notation array and return translated values
       * under the same keys.
       *
       * NOTE: Placeholder protection (:name, URLs, <span translate="no">) is
       * handled entirely by the driver via the ProtectsPlaceholders trait.
       * This service passes raw values — no double-wrapping.
       *
       * Used by: preview system, batch jobs, file translation.
       *
       * @param  array<string, mixed> $flatArray  Dot-notation key → source string map.
       * @param  string               $locale     Target locale code.
       * @return array<string, mixed>             Same keys, translated values.
       */
      public function translateBatch(array $flatArray, string $locale): array
      {
            if (empty($flatArray)) {
                  return [];
            }

            $ignoredKeys = config('lara-glot.ignored_keys', []);
            $toTranslate = [];
            $skipped = [];

            foreach ($flatArray as $key => $value) {
                  $lastSegment = last(explode('.', (string) $key));
                  if (in_array($lastSegment, $ignoredKeys, true)) {
                        $skipped[$key] = $value;
                  } else {
                        $toTranslate[$key] = $value;
                  }
            }

            if (empty($toTranslate)) {
                  return $flatArray;
            }

            $keys = array_keys($toTranslate);
            $values = array_values($toTranslate);

            $translatedValues = $this->translator->translateBatch(
                  $values,
                  $locale,
                  $this->sourceLocale
            );

            if (count($translatedValues) !== count($keys)) {
                  throw new \RuntimeException(sprintf(
                        'Driver returned %d value(s) for %d key(s).',
                        count($translatedValues),
                        count($keys)
                  ));
            }

            $translated = array_combine($keys, $translatedValues);

            $result = [];
            foreach ($flatArray as $key => $value) {
                  $result[$key] = $translated[$key] ?? $skipped[$key] ?? $value;
            }

            return $result;
      }

      /**
       * Save a translated language array to disk.
       *
       * Supports root files and nested subdirectories.
       * Example: 'admin/users' → lang/fr/admin/users.php
       */
      public function saveToFile(string $fileName, string $locale, array $data): void
      {
            $path = $this->getLanguageFilePath($locale, $fileName);
            $directory = dirname($path);

            if (!File::exists($directory)) {
                  File::makeDirectory($directory, 0755, true);
            }

            $content = "<?php\n\nreturn " . $this->arrayToPhpString($data) . ";\n";

            File::put($path, $content);
      }

      /**
       * Get all translatable language files as a flat key → label map.
       *
       * Supports root files, nested files, and recursive scanning.
       * Excludes files listed in lara-glot.exclude_files.
       *
       * Examples: 'auth', 'validation', 'admin/users'
       *
       * @return array<string, string>
       */
      public function getTranslatableFiles(): array
      {
            $directory = lang_path($this->sourceLocale);

            if (!File::exists($directory)) {
                  return [];
            }

            return collect(File::allFiles($directory))
                  ->filter(fn($file) => $file->getExtension() === 'php')
                  ->mapWithKeys(function ($file) use ($directory) {
                        $relative = str_replace(
                              [$directory . DIRECTORY_SEPARATOR, '.php'],
                              '',
                              $file->getPathname()
                        );

                        // Normalize to forward slashes on all platforms.
                        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

                        if (in_array($relative, $this->excludedFiles, true)) {
                              return [];
                        }

                        return [$relative => $relative];
                  })
                  ->sortKeys()
                  ->toArray();
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Helpers
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Build the full path for a language file.
       *
       * Examples:
       *   ('en', 'auth')         → {lang_path}/en/auth.php
       *   ('fr', 'admin/users')  → {lang_path}/fr/admin/users.php
       */
      protected function getLanguageFilePath(string $locale, string $fileName): string
      {
            return lang_path("{$locale}/{$fileName}.php");
      }

      /**
       * Convert a PHP array to a clean short-syntax string for writing to disk.
       *
       * Produces properly indented output with short array syntax ([]).
       * Unlike the var_export + regex approach, this never double-indents or
       * corrupts strings that happen to start with spaces.
       *
       * @param  array<mixed, mixed> $array
       * @param  int                 $depth  Current indentation depth (recursive).
       * @return string                      PHP array literal, e.g. "[\n    'key' => 'value',\n]"
       */
      protected function arrayToPhpString(array $array, int $depth = 0): string
      {
            $indent = str_repeat('    ', $depth);
            $innerIndent = str_repeat('    ', $depth + 1);
            $lines = ['['];

            foreach ($array as $key => $value) {
                  $exportedKey = is_string($key)
                        ? "'" . addslashes($key) . "'"
                        : $key;

                  if (is_array($value)) {
                        $exportedValue = $this->arrayToPhpString($value, $depth + 1);
                  } else {
                        $exportedValue = var_export($value, true);
                  }

                  $lines[] = "{$innerIndent}{$exportedKey} => {$exportedValue},";
            }

            $lines[] = "{$indent}]";

            return implode("\n", $lines);
      }
}