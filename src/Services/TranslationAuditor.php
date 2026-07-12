<?php

namespace Tonydev\LaraGlot\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tonydev\LaraGlot\Concerns\DeterminesTranslatability;

/**
 * Detects (and optionally repairs) silent translation fallbacks.
 *
 * A failed translation run leaves values byte-identical to the source locale
 * with nothing flagging them — the file exists, the model attribute is set,
 * and skip-existing logic hides them forever. The auditor walks both targets:
 *
 *  - Files:  every leaf key of each language file, compared to the source
 *            locale by dot-notation key path. A missing target file counts
 *            as fully untranslated.
 *  - Models: every translatable attribute (Spatie HasTranslations duck-typed
 *            via getTranslatableAttributes/getTranslations) of every record
 *            of each model registered in lara-glot.models.
 *
 * "Identical" only counts values that were eligible for translation in the
 * first place — ignored keys, protected values (route:/URLs), blanks, and
 * the audit allowlist (lara-glot.audit.allowlist — "OK", brand names, …)
 * are excluded so legitimately-identical strings don't skew the ratio or
 * get re-billed by repair.
 *
 * Repair re-translates ONLY the identical values, with force=true so a
 * poisoned cache entry (source text cached as the translation) is evicted
 * rather than served straight back.
 */
class TranslationAuditor
{
      use DeterminesTranslatability;

      public function __construct(
            protected TranslationService $translator,
            protected FileTranslationService $files,
      ) {
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Files
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Audit (and optionally repair) language files.
       *
       * @param  list<string> $locales    Target locales to audit.
       * @param  list<string> $onlyFiles  Restrict to these file base names; empty = all.
       * @param  bool         $repair     Re-translate identical values in place.
       * @return list<array{type: string, target: string, locale: string,
       *               eligible: int, identical: int, ratio: float,
       *               repaired: int, error: ?string}>
       */
      public function auditFiles(array $locales, array $onlyFiles = [], bool $repair = false): array
      {
            $sourceLocale = config('lara-glot.source_locale', 'en');
            $fileNames = array_keys($this->files->getTranslatableFiles());

            if (!empty($onlyFiles)) {
                  $fileNames = array_values(array_intersect($fileNames, $onlyFiles));
            }

            $rows = [];

            foreach ($fileNames as $fileName) {
                  $sourceFlat = $this->loadFlat($sourceLocale, $fileName);

                  if ($sourceFlat === null) {
                        continue;
                  }

                  $eligible = array_filter(
                        $sourceFlat,
                        fn($value, $key) => $this->isAuditable($key, $value),
                        ARRAY_FILTER_USE_BOTH
                  );

                  foreach ($locales as $locale) {
                        $rows[] = $this->auditOneFile(
                              $fileName,
                              $locale,
                              $sourceFlat,
                              $eligible,
                              $repair
                        );
                  }
            }

            return $rows;
      }

      /**
       * @param  array<string, mixed> $sourceFlat  Full flattened source file.
       * @param  array<string, string> $eligible   Auditable subset of $sourceFlat.
       */
      protected function auditOneFile(
            string $fileName,
            string $locale,
            array $sourceFlat,
            array $eligible,
            bool $repair
      ): array {
            $sourceLocale = config('lara-glot.source_locale', 'en');
            $targetFlat = $this->loadFlat($locale, $fileName) ?? [];

            // Missing keys count as untranslated: at runtime they fall back to
            // the source locale, which is indistinguishable from a silent
            // fallback from the user's point of view.
            $identicalKeys = [];

            foreach ($eligible as $key => $sourceValue) {
                  if (!array_key_exists($key, $targetFlat) || $targetFlat[$key] === $sourceValue) {
                        $identicalKeys[$key] = $sourceValue;
                  }
            }

            $repaired = 0;
            $error = null;

            if ($repair && !empty($identicalKeys)) {
                  try {
                        $fixed = $this->translator->translateBatch(
                              $identicalKeys,
                              $locale,
                              $sourceLocale,
                              force: true // evict poisoned cache entries
                        );

                        // Base layer is the source file so structural drift
                        // (keys added since the last run) is healed too; the
                        // existing target translations win, then the repairs.
                        $finalFlat = array_merge($sourceFlat, $targetFlat, $fixed);

                        $nested = [];
                        foreach ($finalFlat as $key => $value) {
                              Arr::set($nested, $key, $value);
                        }

                        $this->files->saveToFile($fileName, $locale, $nested);

                        $repaired = count($identicalKeys);
                        $targetFlat = $finalFlat;

                        // Recount: whatever is still identical after a forced
                        // re-translation is either legitimately identical
                        // (allowlist candidate) or still failing.
                        $identicalKeys = array_filter(
                              $identicalKeys,
                              fn($sourceValue, $key) => ($targetFlat[$key] ?? $sourceValue) === $sourceValue,
                              ARRAY_FILTER_USE_BOTH
                        );

                  } catch (\Throwable $e) {
                        $error = $e->getMessage();

                        Log::error('[LaraGlot:Audit] File repair failed.', [
                              'file' => $fileName,
                              'locale' => $locale,
                              'error' => $error,
                        ]);
                  }
            }

            $total = count($eligible);
            $identical = count($identicalKeys);

            return [
                  'type' => 'file',
                  'target' => $fileName,
                  'locale' => $locale,
                  'eligible' => $total,
                  'identical' => $identical,
                  'ratio' => $total > 0 ? round($identical / $total, 4) : 0.0,
                  'repaired' => $repaired,
                  'error' => $error,
            ];
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Models
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Audit (and optionally repair) translatable model attributes.
       *
       * @param  list<string> $locales     Target locales to audit.
       * @param  list<string> $onlyModels  Restrict to these classes; empty = all configured.
       * @return list<array{type: string, target: string, locale: string,
       *               eligible: int, identical: int, ratio: float,
       *               repaired: int, error: ?string}>
       */
      public function auditModels(array $locales, array $onlyModels = [], bool $repair = false): array
      {
            $sourceLocale = config('lara-glot.source_locale', 'en');
            $modelClasses = (array) config('lara-glot.models', []);

            if (!empty($onlyModels)) {
                  $modelClasses = array_values(array_intersect($modelClasses, $onlyModels));
            }

            $rows = [];

            foreach ($modelClasses as $modelClass) {
                  if (!class_exists($modelClass)) {
                        Log::warning("[LaraGlot:Audit] Model class not found, skipping: {$modelClass}");
                        continue;
                  }

                  if (!method_exists($modelClass, 'getTranslatableAttributes')) {
                        Log::warning("[LaraGlot:Audit] {$modelClass} has no HasTranslations trait, skipping.");
                        continue;
                  }

                  // counts[locale] => ['eligible' => n, 'identical' => n, 'repaired' => n]
                  $counts = array_fill_keys($locales, ['eligible' => 0, 'identical' => 0, 'repaired' => 0]);
                  $error = null;

                  try {
                        $modelClass::withoutGlobalScopes()->chunkById(
                              100,
                              function ($records) use ($locales, $sourceLocale, $repair, &$counts) {
                                    foreach ($records as $record) {
                                          $this->auditOneRecord($record, $locales, $sourceLocale, $repair, $counts);
                                    }
                              }
                        );
                  } catch (\Throwable $e) {
                        $error = $e->getMessage();

                        Log::error('[LaraGlot:Audit] Model audit failed.', [
                              'model' => $modelClass,
                              'error' => $error,
                        ]);
                  }

                  foreach ($locales as $locale) {
                        $total = $counts[$locale]['eligible'];
                        $identical = $counts[$locale]['identical'];

                        $rows[] = [
                              'type' => 'model',
                              'target' => $modelClass,
                              'locale' => $locale,
                              'eligible' => $total,
                              'identical' => $identical,
                              'ratio' => $total > 0 ? round($identical / $total, 4) : 0.0,
                              'repaired' => $counts[$locale]['repaired'],
                              'error' => $error,
                        ];
                  }
            }

            return $rows;
      }

      /**
       * Audit one record's translatable attributes, repairing in place when
       * requested. Mutates $counts per locale.
       */
      protected function auditOneRecord(
            object $record,
            array $locales,
            string $sourceLocale,
            bool $repair,
            array &$counts
      ): void {
            $recordChanged = false;

            foreach ($record->getTranslatableAttributes() as $attribute) {
                  $translations = $record->getTranslations($attribute);

                  if (!is_array($translations)) {
                        continue;
                  }

                  $sourceValue = $translations[$sourceLocale] ?? null;

                  if (!$this->isAuditable($attribute, $sourceValue)) {
                        continue;
                  }

                  $attributeChanged = false;

                  foreach ($locales as $locale) {
                        $counts[$locale]['eligible']++;

                        $current = $translations[$locale] ?? null;
                        $untranslated = $current === null || $current === '' || $current === $sourceValue;

                        if (!$untranslated) {
                              continue;
                        }

                        if ($repair) {
                              try {
                                    $fixed = $this->translator->translate(
                                          $sourceValue,
                                          $locale,
                                          $sourceLocale,
                                          force: true // evict poisoned cache entries
                                    );
                              } catch (\Throwable $e) {
                                    Log::error('[LaraGlot:Audit] Model repair failed for one value.', [
                                          'model' => get_class($record),
                                          'id' => method_exists($record, 'getKey') ? $record->getKey() : null,
                                          'attribute' => $attribute,
                                          'locale' => $locale,
                                          'error' => $e->getMessage(),
                                    ]);

                                    $fixed = $sourceValue;
                              }

                              $counts[$locale]['repaired']++;

                              if ($fixed !== $sourceValue && $fixed !== '') {
                                    $translations[$locale] = $fixed;
                                    $attributeChanged = true;
                                    continue; // repaired — not identical anymore
                              }
                        }

                        $counts[$locale]['identical']++;
                  }

                  if ($attributeChanged) {
                        $record->setTranslations($attribute, $translations);
                        $recordChanged = true;
                  }
            }

            if ($recordChanged) {
                  $record->saveQuietly();
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Helpers
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Load a language file as a flat dot-notation array, or null when the
       * file does not exist / does not return an array.
       *
       * @return array<string, mixed>|null
       */
      protected function loadFlat(string $locale, string $fileName): ?array
      {
            $path = lang_path("{$locale}/{$fileName}.php");

            if (!File::exists($path)) {
                  return null;
            }

            $data = File::getRequire($path);

            return is_array($data) ? Arr::dot($data) : null;
      }
}
