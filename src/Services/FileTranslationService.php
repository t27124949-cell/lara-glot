<?php

namespace Tonydev\LaraGlot\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class FileTranslationService
{
      protected $translator;

      public function __construct(TranslationService $translator)
      {
            $this->translator = $translator;
      }

      /**
       * Translate a whole file (Used by CLI)
       */
      public function translateFile(string $fileName, string $targetLocale)
      {
            $sourcePath = lang_path("en/{$fileName}.php");

            if (!File::exists($sourcePath)) {
                  Log::error("❌ [LaraGlot] Source file not found", ['path' => $sourcePath]);
                  throw new \Exception("Source file [en/{$fileName}.php] not found.");
            }

            $translations = include $sourcePath;

            if (!is_array($translations))
                  return;

            $flatArray = Arr::dot($translations);

            // Use the public method internally too
            $translatedFlat = $this->translateBatch($flatArray, $targetLocale);

            $translatedNested = [];
            foreach ($translatedFlat as $key => $value) {
                  Arr::set($translatedNested, $key, $value);
            }

            $this->saveToFile($fileName, $targetLocale, $translatedNested);
      }

      /**
       * CHANGED TO PUBLIC: Used by Filament UI to get previews
       */
      public function translateBatch(array $flatArray, string $locale): array
      {
            $keys = array_keys($flatArray);
            $values = array_values($flatArray);

            $maskedValues = array_map(function ($text) {
                  return is_string($text) ? $this->maskPlaceholders($text) : $text;
            }, $values);

            $translatedValues = $this->translator->translateBatch($maskedValues, $locale, 'en');

            $finalValues = array_map(
                  fn($text) => is_string($text) ? $this->restorePlaceholders($text) : $text,
                  $translatedValues
            );

            return array_combine($keys, $finalValues);
      }

      /**
       * CHANGED TO PUBLIC: Used by Filament UI to save edited content
       */
      public function saveToFile($fileName, $locale, $data)
      {
            $directory = lang_path($locale);
            if (!File::exists($directory)) {
                  File::makeDirectory($directory, 0755, true);
            }

            $path = "{$directory}/{$fileName}.php";

            // Clean conversion to short array syntax
            $export = var_export($data, true);
            $export = preg_replace('/^([ ]*)(.*)/m', '$1$1$2', $export);
            $array = str_replace(['array (', ')'], ['[', ']'], $export);

            $content = "<?php\n\nreturn " . $array . ";\n";

            File::put($path, $content);
      }

      protected function maskPlaceholders(string $text): string
      {
            if (str_contains($text, '|')) {
                  $parts = explode('|', $text);
                  return implode('|', array_map(fn($p) => $this->maskPlaceholders(trim($p)), $parts));
            }

            return preg_replace('/:([a-zA-Z0-9_]+)/', '<span translate="no">:$1</span>', $text);
      }

      protected function restorePlaceholders(string $translatedText): string
      {
            $text = html_entity_decode($translatedText, ENT_QUOTES, 'UTF-8');
            $text = preg_replace('/<span\b[^>]*>/i', '', $text);
            $text = str_ireplace('</span>', '', $text);

            // Fix spaces around placeholders like "Hello :name !" -> "Hello :name!"
            $text = preg_replace('/\s?(:[a-zA-Z0-9_]+)\s?/', ' $1', $text);

            return trim($text);
      }

      public function getTranslatableFiles(): array
      {
            $sourceLocale = config('lara-glot.source_locale', 'en');
            $directory = lang_path($sourceLocale);

            if (!File::exists($directory))
                  return [];

            return collect(File::files($directory))
                  ->mapWithKeys(function ($file) {
                        $name = $file->getFilenameWithoutExtension();
                        return [$name => $name];
                  })
                  ->except(config('lara-glot.exclude_files', []))
                  ->toArray();
      }
}