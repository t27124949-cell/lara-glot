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
            // Use the facade to avoid global function issues in some environments
            DB::connection()->disableQueryLog();

            $lockKey = 'lara-glot.translating.' . $model->getTable() . '.' . $model->id;
            $lock = Cache::lock($lockKey, 120);

            if (!$lock->get()) {
                  Log::info("Translation skipped due to lock for {$model->getTable()} ID {$model->id}");
                  return;
            }

            try {
                  $model->refresh();

                  if (method_exists($model, 'sections')) {
                        $model->loadMissing('sections');
                  }

                  if (method_exists($model, 'getTranslatableAttributes')) {
                        $this->ensureSeoPopulated($model);

                        $changed = false;
                        foreach ($model->getTranslatableAttributes() as $field) {
                              $translations = $model->getTranslations($field);
                              if (!is_array($translations))
                                    continue;

                              $updated = $this->processTranslationLogic($translations, $force);
                              if ($updated !== $translations) {
                                    $model->setTranslations($field, $updated);
                                    $changed = true;
                              }
                        }

                        if ($changed) {
                              $model->saveQuietly();
                        }
                  }

                  if (method_exists($model, 'sections')) {
                        $this->translateSections($model, $force);
                  }

                  Log::info("Translation completed for {$model->getTable()} ID {$model->id}");
            } catch (\Throwable $e) {
                  Log::error("Translation failed for {$model->getTable()} ID {$model->id}: " . $e->getMessage());
                  throw $e;
            } finally {
                  optional($lock)->release();
            }
      }

      protected function ensureSeoPopulated(Model $model): void
      {
            // This logic stays the same - it's great for automated SEO
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
            // FIXED: Pointing to lara-glot config
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
            if (empty($en))
                  return $translations;

            // FIXED: Pointing to lara-glot config
            $locales = $locales ?? array_keys(config('lara-glot.languages', ['en' => 'English']));
            $lastEn = $translations['_en_original'] ?? null;
            $enWasModified = $force || ($en !== $lastEn);

            foreach ($locales as $locale) {
                  if ($locale === 'en' || $this->shouldSkipKey($locale))
                        continue;

                  if ($enWasModified || empty($translations[$locale])) {
                        $translations[$locale] = is_array($en)
                              ? $this->translateNestedArray($en, $locale)
                              : $this->safeTranslate((string) $en, $locale);
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
                  // Your chunking logic is vital here for M4 stability
                  if (strlen($text) > 2000) {
                        return $this->translateInChunks($text, $locale);
                  }

                  return html_entity_decode(
                        $this->translator->translate($text, $locale),
                        ENT_QUOTES,
                        'UTF-8'
                  );
            } catch (\Throwable $e) {
                  Log::warning("Translation failed for locale {$locale}: " . $e->getMessage());
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
            return in_array($key, [
                  'id',
                  'slug',
                  'url',
                  'image',
                  'icon',
                  'primary_url',
                  'secondary_url',
                  'cta_url',
                  'autoplay_speed',
                  'sort_order',
                  'layout_type',
                  '_en_original',
                  'en_original'
            ]);
      }
}