<?php

namespace Tonydev\LaraGlot\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SmartTranslationService
{
      protected TranslationService $translator;

      public function __construct(TranslationService $translator)
      {
            $this->translator = $translator;
      }

      /**
       * MAIN ENTRY: Translate a full model including sections
       */
      public function translateModel(Model $model, bool $force = false): void
      {
            $modelName = class_basename($model);
            $modelId = $model->id;

            Log::info("🔍 [LaraGlot] Starting translation for $modelName ID $modelId");

            // Check if model has translatable trait
            if (!method_exists($model, 'getTranslatableAttributes')) {
                  Log::error("❌ [LaraGlot] Model $modelName does not have HasTranslations trait");
                  return;
            }

            DB::connection()->disableQueryLog();

            $lockKey = 'lara-glot.translating.' . $model->getTable() . '.' . $model->id;
            $lock = Cache::lock($lockKey, 120);

            if (!$lock->get()) {
                  Log::info("⏸️ [LaraGlot] Translation skipped due to lock for {$model->getTable()} ID {$model->id}");
                  return;
            }

            try {
                  $model->refresh();
                  Log::info("📝 [LaraGlot] Model refreshed");

                  if (method_exists($model, 'sections')) {
                        $model->loadMissing('sections');
                        Log::info("📚 [LaraGlot] Sections loaded");
                  }

                  if (method_exists($model, 'getTranslatableAttributes')) {
                        $translatableAttrs = $model->getTranslatableAttributes();
                        Log::info("📋 [LaraGlot] Translatable attributes: " . json_encode($translatableAttrs));

                        $this->ensureSeoPopulated($model);

                        $changed = false;
                        foreach ($translatableAttrs as $field) {
                              Log::info("🔄 [LaraGlot] Processing field: $field");

                              $translations = $model->getTranslations($field);
                              Log::info("📊 [LaraGlot] Current translations for $field: " . json_encode($translations));

                              if (!is_array($translations)) {
                                    Log::warning("⚠️ [LaraGlot] Field $field translations is not an array");
                                    continue;
                              }

                              $updated = $this->processTranslationLogic($translations, $force);
                              Log::info("✨ [LaraGlot] Updated translations for $field: " . json_encode($updated));

                              if ($updated !== $translations) {
                                    Log::info("💾 [LaraGlot] Setting translations for $field");
                                    $model->setTranslations($field, $updated);
                                    $changed = true;
                              }
                        }

                        if ($changed) {
                              Log::info("💾 [LaraGlot] Saving model with changes");
                              $model->saveQuietly();
                              Log::info("✅ [LaraGlot] Model saved successfully");
                        } else {
                              Log::info("ℹ️ [LaraGlot] No changes detected, skipping save");
                        }
                  }

                  if (method_exists($model, 'sections')) {
                        Log::info("📚 [LaraGlot] Starting section translation");
                        $this->translateSections($model, $force);
                  }

                  Log::info("🎉 [LaraGlot] Translation completed for $modelName ID $modelId");
            } catch (\Throwable $e) {
                  Log::error("❌ [LaraGlot] Translation failed for {$model->getTable()} ID {$model->id}: " . $e->getMessage(), [
                        'trace' => $e->getTraceAsString(),
                  ]);
                  throw $e;
            } finally {
                  optional($lock)->release();
            }
      }

      protected function ensureSeoPopulated(Model $model): void
      {
            if (!$model->isTranslatableAttribute('title'))
                  return;

            $enTitle = $model->getTranslation('title', 'en');
            if (!$enTitle)
                  return;

            if ($model->isTranslatableAttribute('meta_title') && empty($model->getTranslation('meta_title', 'en'))) {
                  $model->setTranslation('meta_title', 'en', $enTitle);
            }

            if ($model->isTranslatableAttribute('meta_description') && empty($model->getTranslation('meta_description', 'en'))) {
                  $model->setTranslation('meta_description', 'en', Str::limit(strip_tags($enTitle), 160));
            }
      }

      protected function translateSections(Model $model, bool $force = false): void
      {
            $locales = array_keys(config('lara-glot.languages', ['en' => 'English']));

            foreach ($model->sections as $section) {
                  $original = $section->content;
                  if (!is_array($original))
                        continue;

                  $updated = $this->translateFieldLevel($original, $locales, $force);

                  if ($updated !== $original) {
                        $section->content = $updated;
                        $section->saveQuietly();
                  }
            }
      }

      protected function translateFieldLevel(array $data, array $locales, bool $force = false): array
      {
            foreach ($data as $key => $value) {
                  if ($this->shouldSkipKey($key))
                        continue;

                  if (is_array($value) && isset($value['en'])) {
                        $data[$key] = $this->processTranslationLogic($value, $force, $locales);
                  } elseif (is_array($value)) {
                        $data[$key] = $this->translateFieldLevel($value, $locales, $force);
                  }
            }
            return $data;
      }

      protected function processTranslationLogic(array $translations, bool $force = false, ?array $locales = null): array
      {
            $en = $translations['en'] ?? null;
            if (empty($en)) {
                  Log::warning("⚠️ [LaraGlot] No English translation found");
                  return $translations;
            }

            $locales = $locales ?? array_keys(config('lara-glot.languages', ['en' => 'English']));
            $lastEn = $translations['_en_original'] ?? null;
            $enWasModified = $force || ($en !== $lastEn);

            Log::info("📌 [LaraGlot] Force: $force, EN Modified: " . ($enWasModified ? 'yes' : 'no'));

            foreach ($locales as $locale) {
                  if ($locale === 'en' || $this->shouldSkipKey($locale))
                        continue;

                  $hasTranslation = !empty($translations[$locale]);

                  if ($enWasModified || !$hasTranslation) {
                        Log::info("🌐 [LaraGlot] Translating to $locale (has existing: " . ($hasTranslation ? 'yes' : 'no') . ")");

                        $translated = is_array($en)
                              ? $this->translateNestedArray($en, $locale)
                              : $this->safeTranslate((string) $en, $locale);

                        $translations[$locale] = $translated;
                        Log::info("✅ [LaraGlot] $locale translation set");
                  } else {
                        Log::info("⏭️ [LaraGlot] Skipping $locale - already translated and source unchanged");
                  }
            }

            $translations['_en_original'] = $en;
            return $translations;
      }

      protected function translateNestedArray(array $data, string $locale): array
      {
            foreach ($data as $key => $value) {
                  if ($this->shouldSkipKey($key))
                        continue;

                  if (is_string($value) && !empty($value)) {
                        $data[$key] = $this->safeTranslate($value, $locale);
                  } elseif (is_array($value)) {
                        $data[$key] = $this->translateNestedArray($value, $locale);
                  }
            }
            return $data;
      }

      protected function safeTranslate(mixed $text, string $locale): string
      {
            if (!is_string($text) || empty(trim($text))) {
                  return is_array($text) ? '' : (string) $text;
            }

            try {
                  if (strlen($text) > 2000) {
                        return $this->translateInChunks($text, $locale);
                  }

                  return html_entity_decode(
                        $this->translator->translate($text, $locale),
                        ENT_QUOTES,
                        'UTF-8'
                  );
            } catch (\Throwable $e) {
                  Log::warning("⚠️ [LaraGlot] Translation failed for locale {$locale}: " . $e->getMessage());
                  return $text;
            }
      }

      protected function translateInChunks(string $text, string $locale): string
      {
            $chunks = preg_split('/(?<=<\/p>|<\/h[1-6]>|\.|\n)/i', $text, -1, PREG_SPLIT_NO_EMPTY);
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

      protected function shouldSkipKey(string $key): bool
      {
            // Fetch from config, fallback to a sensible empty array if not set
            $ignoredKeys = config('lara-glot.ignored_keys', []);

            return in_array($key, $ignoredKeys);
      }
}