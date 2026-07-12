<?php

namespace Tonydev\LaraGlot\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tonydev\LaraGlot\Concerns\DeterminesTranslatability;
use Tonydev\LaraGlot\Concerns\TransportsConcurrencyResults;
use Tonydev\LaraGlot\Services\TranslationService;

class SmartTranslationService
{
      use DeterminesTranslatability;
      use TransportsConcurrencyResults;

      /**
       * The translation service — provides in-process caching, force-refresh,
       * and text normalisation on top of the raw driver.
       */
      protected TranslationService $translator;

      /** Dead-letter state for values that fell back to source text. */
      protected RetryQueue $retryQueue;

      public function __construct(TranslationService $translator, ?RetryQueue $retryQueue = null)
      {
            $this->translator = $translator;
            $this->retryQueue = $retryQueue ?? new RetryQueue();
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Main entry point
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate all translatable attributes on a model, including sections.
       *
       * @param array $locales  Target locale codes. Empty = all configured locales.
       */
      public function translateModel(
            Model $model,
            bool $force = false,
            array $locales = []  // ✅ locales propagated from TranslateModelJob
      ): void {
            $modelName = class_basename($model);
            $modelId = $model->getKey(); // ✅ works for any PK name/type

            Log::info("🔍 [LaraGlot] Starting translation for {$modelName} ID {$modelId}");

            if (!method_exists($model, 'getTranslatableAttributes')) {
                  Log::error("❌ [LaraGlot] Model {$modelName} does not have HasTranslations trait");
                  return;
            }

            // ── Distributed lock — prevents double-translation on concurrent jobs ──
            // Scoped to table + PK so different records never block each other.
            $lockKey = 'lara-glot.translating.' . $model->getTable() . '.' . $modelId;
            $lock = Cache::lock($lockKey, 120);

            if (!$lock->get()) {
                  Log::info("⏸️ [LaraGlot] Translation skipped (lock held) for {$model->getTable()} ID {$modelId}");
                  return;
            }

            try {
                  $model->refresh();

                  if (method_exists($model, 'sections')) {
                        $model->loadMissing('sections');
                  }

                  $translatableAttrs = $model->getTranslatableAttributes();
                  Log::info("📋 [LaraGlot] Translatable attributes: " . implode(', ', $translatableAttrs));

                  $this->ensureSeoPopulated($model);

                  $changed = false;

                  foreach ($translatableAttrs as $field) {
                        $translations = $model->getTranslations($field);

                        if (!is_array($translations)) {
                              Log::warning("⚠️ [LaraGlot] Field {$field} translations is not an array — skipping");
                              continue;
                        }

                        // Pass $locales so only selected languages are targeted.
                        $updated = $this->processTranslationLogic($translations, $force, $locales ?: null);

                        if ($updated !== $translations) {
                              $model->setTranslations($field, $updated);
                              $changed = true;
                        }
                  }

                  if ($changed) {
                        Log::info("💾 [LaraGlot] Saving {$modelName} ID {$modelId}");
                        $model->saveQuietly();
                        Log::info("✅ [LaraGlot] Model saved successfully");
                  } else {
                        Log::info("ℹ️ [LaraGlot] No changes detected for {$modelName} ID {$modelId}");
                  }

                  // Never silently fall back: record attributes whose target-
                  // locale value is still missing or identical to the source
                  // so laraglot:retry can re-attempt them on a schedule.
                  $this->recordFallbacks($model, $translatableAttrs, $locales);

                  if (method_exists($model, 'sections')) {
                        $this->translateSections($model, $force, $locales);
                  }

                  Log::info("🎉 [LaraGlot] Translation completed for {$modelName} ID {$modelId}");

            } catch (\Throwable $e) {
                  Log::error("❌ [LaraGlot] Translation failed for {$model->getTable()} ID {$modelId}: " . $e->getMessage(), [
                        'trace' => $e->getTraceAsString(),
                  ]);
                  throw $e;

            } finally {
                  $lock->release();
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Fallback recording
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Record every attribute/locale pair whose value is still missing or
       * identical to the English source after a translation run, so the
       * laraglot:retry command can re-attempt them later. Best-effort — a
       * missing retry table degrades to a logged notice inside RetryQueue.
       *
       * @param list<string> $attributes  The model's translatable attributes.
       * @param list<string> $locales     Requested locales; empty = all configured.
       */
      protected function recordFallbacks(Model $model, array $attributes, array $locales): void
      {
            $targetLocales = ($locales ?: array_keys(config('lara-glot.languages', [])));

            foreach ($attributes as $attribute) {
                  $translations = $model->getTranslations($attribute);

                  if (!is_array($translations)) {
                        continue;
                  }

                  $sourceValue = $translations['en'] ?? null;

                  if (!$this->isAuditable($attribute, $sourceValue)) {
                        continue;
                  }

                  foreach ($targetLocales as $locale) {
                        if ($locale === 'en' || $this->shouldSkipKey($locale)) {
                              continue;
                        }

                        $current = $translations[$locale] ?? null;

                        if ($current === null || $current === '' || $current === $sourceValue) {
                              $this->retryQueue->record(
                                    'model',
                                    get_class($model),
                                    $model->getKey() . ':' . $attribute,
                                    $locale,
                                    $sourceValue
                              );
                        }
                  }
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // SEO auto-population
      // ─────────────────────────────────────────────────────────────────────────

      protected function ensureSeoPopulated(Model $model): void
      {
            if (!$model->isTranslatableAttribute('title')) {
                  return;
            }

            $enTitle = $model->getTranslation('title', 'en');

            if (!$enTitle) {
                  return;
            }

            if (
                  $model->isTranslatableAttribute('meta_title') &&
                  empty($model->getTranslation('meta_title', 'en'))
            ) {
                  $model->setTranslation('meta_title', 'en', $enTitle);
            }

            if (
                  $model->isTranslatableAttribute('meta_description') &&
                  empty($model->getTranslation('meta_description', 'en'))
            ) {
                  $model->setTranslation(
                        'meta_description',
                        'en',
                        Str::limit(strip_tags($enTitle), 160)
                  );
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Section translation
      // ─────────────────────────────────────────────────────────────────────────

      protected function translateSections(Model $model, bool $force = false, array $locales = []): void
      {
            $resolvedLocales = !empty($locales)
                  ? $locales
                  : array_keys(config('lara-glot.languages', ['en' => 'English']));

            foreach ($model->sections as $section) {
                  $original = $section->content;

                  if (!is_array($original)) {
                        continue;
                  }

                  $updated = $this->translateFieldLevel($original, $resolvedLocales, $force);

                  if ($updated !== $original) {
                        $section->content = $updated;
                        $section->saveQuietly();
                  }
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Field-level recursive translation
      // ─────────────────────────────────────────────────────────────────────────

      protected function translateFieldLevel(array $data, array $locales, bool $force = false): array
      {
            foreach ($data as $key => $value) {
                  if ($this->shouldSkipKey((string) $key)) {
                        continue;
                  }

                  if (is_array($value) && isset($value['en'])) {
                        $data[$key] = $this->processTranslationLogic($value, $force, $locales);
                  } elseif (is_array($value)) {
                        $data[$key] = $this->translateFieldLevel($value, $locales, $force);
                  }
            }

            return $data;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Core translation logic
      // ─────────────────────────────────────────────────────────────────────────

      protected function processTranslationLogic(
            array $translations,
            bool $force = false,
            ?array $locales = null
      ): array {
            $en = $translations['en'] ?? null;

            if (empty($en)) {
                  Log::warning('⚠️ [LaraGlot] No English source found — skipping');
                  return $translations;
            }

            $resolvedLocales = $locales ?? array_keys(config('lara-glot.languages', ['en' => 'English']));
            $lastEn = $translations['_en_original'] ?? null;
            $enWasModified = $force || ($en !== $lastEn);

            // ── Determine which locales actually need translating ─────────────────
            $localesToTranslate = [];

            foreach ($resolvedLocales as $locale) {
                  if ($locale === 'en' || $this->shouldSkipKey($locale)) {
                        continue;
                  }

                  $hasTranslation = !empty($translations[$locale]);

                  if ($enWasModified || !$hasTranslation) {
                        $localesToTranslate[] = $locale;
                  } else {
                        Log::info("⏭️ [LaraGlot] Skipping {$locale} — already translated and source unchanged");
                  }
            }

            if (empty($localesToTranslate)) {
                  return $translations;
            }

            Log::info('🚀 [LaraGlot] Translating ' . count($localesToTranslate) . ' locale(s) in parallel chunks');

            // ── Build one closure per locale ──────────────────────────────────────
            $tasks = [];
            foreach ($localesToTranslate as $locale) {
                  $tasks[$locale] = function () use ($en, $locale): mixed {
                        return is_array($en)
                              ? $this->translateNestedArray($en, $locale)
                              : $this->safeTranslate((string) $en, $locale);
                  };
            }

            // ── Run in batches of 5, preserving locale keys ───────────────────────
            // runConcurrentBatches() (TransportsConcurrencyResults) re-applies the
            // locale keys AND base64-wraps results so multibyte translations
            // survive the `process` concurrency driver intact.
            $results = $this->runConcurrentBatches($tasks, 5);

            // ── Apply results back to the translations array ──────────────────────
            foreach ($results as $locale => $translatedText) {
                  $translations[$locale] = $translatedText;
                  Log::info("✅ [LaraGlot] {$locale} translation completed");
            }

            // Store the current English value so next run can detect changes.
            $translations['_en_original'] = $en;

            return $translations;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Nested array translation
      // ─────────────────────────────────────────────────────────────────────────

      protected function translateNestedArray(array $data, string $locale): array
      {
            foreach ($data as $key => $value) {
                  if ($this->shouldSkipKey((string) $key)) {
                        continue;
                  }

                  if (is_string($value) && !empty($value)) {
                        $data[$key] = $this->safeTranslate($value, $locale);
                  } elseif (is_array($value)) {
                        $data[$key] = $this->translateNestedArray($value, $locale);
                  }
            }

            return $data;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Safe single-string translation
      // ─────────────────────────────────────────────────────────────────────────

      protected function safeTranslate(mixed $text, string $locale): string
      {
            if (!is_string($text) || empty(trim($text))) {
                  return is_array($text) ? '' : (string) $text;
            }

            try {
                  if (strlen($text) > 2000) {
                        return $this->translateInChunks($text, $locale);
                  }

                  // ✅ No html_entity_decode here — the driver's normalizeTranslated()
                  // already decodes entities. Decoding twice corrupts &amp; and &#39;.
                  return $this->translator->translate($text, $locale);

            } catch (\Throwable $e) {
                  Log::warning("⚠️ [LaraGlot] Translation failed for locale {$locale}: " . $e->getMessage());
                  return $text;
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Long-text chunking
      // ─────────────────────────────────────────────────────────────────────────

      protected function translateInChunks(string $text, string $locale): string
      {
            $chunks = preg_split(
                  '/(?<=<\/p>|<\/h[1-6]>|\.|\n)/i',
                  $text,
                  -1,
                  PREG_SPLIT_NO_EMPTY
            );

            $result = '';
            $batch = '';

            foreach ($chunks as $chunk) {
                  if (strlen($batch . $chunk) > 2000 && !empty($batch)) {
                        $result .= $this->safeTranslate($batch, $locale);
                        $batch = '';
                  }
                  $batch .= $chunk;
            }

            if (!empty($batch)) {
                  $result .= $this->safeTranslate($batch, $locale);
            }

            return $result;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Key filtering
      // ─────────────────────────────────────────────────────────────────────────

      protected function shouldSkipKey(string $key): bool
      {
            return in_array($key, config('lara-glot.ignored_keys', []), true);
      }
}
