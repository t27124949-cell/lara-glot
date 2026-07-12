<?php

namespace Tonydev\LaraGlot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Tonydev\LaraGlot\Concerns\DeterminesTranslatability;
use Tonydev\LaraGlot\Models\TranslationRetry;
use Tonydev\LaraGlot\Services\FileTranslationService;
use Tonydev\LaraGlot\Services\RetryQueue;
use Tonydev\LaraGlot\Services\TranslationService;

/**
 * Re-attempts translation units that previously fell back to source text
 * (recorded by FileTranslationService / SmartTranslationService in the
 * lara_glot_translation_retries table).
 *
 * Designed to run on a schedule: Schedule::command('laraglot:retry').
 * Back-off between attempts and the hard attempt cap live in RetryQueue;
 * exhausted units are surfaced here for human review instead of spinning
 * forever on strings that will never translate.
 */
class RetryTranslationsCommand extends Command
{
      use DeterminesTranslatability;

      protected $signature = 'laraglot:retry
                              {--limit=500 : Maximum units to process in one run}';

      protected $description = 'Re-attempt translation units that fell back to source text, with exponential back-off and a bounded attempt cap';

      public function handle(
            RetryQueue $queue,
            TranslationService $translator,
            FileTranslationService $files
      ): int {
            $units = $queue->due((int) $this->option('limit'));

            if ($units->isEmpty()) {
                  $this->info('✅ No translation units due for retry.');

                  return $this->reportExhausted($queue);
            }

            $this->info("🔁 Retrying {$units->count()} translation unit(s)…");

            $resolved = 0;
            $rescheduled = 0;

            // ── File units: grouped per (file, locale) → one write per file ───────
            $fileGroups = $units
                  ->where('type', 'file')
                  ->groupBy(fn(TranslationRetry $u) => "{$u->target}|{$u->locale}");

            foreach ($fileGroups as $group) {
                  [$r, $s] = $this->retryFileGroup($group->values(), $queue, $translator, $files);
                  $resolved += $r;
                  $rescheduled += $s;
            }

            // ── Model units: one record at a time ─────────────────────────────────
            foreach ($units->where('type', 'model') as $unit) {
                  $this->retryModelUnit($unit, $queue, $translator)
                        ? $resolved++
                        : $rescheduled++;
            }

            $this->info("✅ {$resolved} resolved, {$rescheduled} rescheduled with back-off.");

            return $this->reportExhausted($queue);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // File units
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * @param  \Illuminate\Support\Collection<int, TranslationRetry> $group  Same file + locale.
       * @return array{0: int, 1: int}  [resolved, rescheduled]
       */
      protected function retryFileGroup($group, RetryQueue $queue, TranslationService $translator, FileTranslationService $files): array
      {
            $first = $group->first();
            $fileName = $first->target;
            $locale = $first->locale;
            $sourceLocale = config('lara-glot.source_locale', 'en');

            $sourceFlat = $this->loadFlat($sourceLocale, $fileName);

            // Source file gone — every unit for it is stale.
            if ($sourceFlat === null) {
                  $group->each(fn($unit) => $queue->markResolved($unit));

                  return [$group->count(), 0];
            }

            $targetFlat = $this->loadFlat($locale, $fileName) ?? [];

            $resolved = 0;
            $rescheduled = 0;
            $toTranslate = [];   // dot-key => source value
            $unitsByKey = [];    // dot-key => TranslationRetry

            foreach ($group as $unit) {
                  $sourceValue = $sourceFlat[$unit->item_key] ?? null;

                  // Stale: key removed, source text changed, or no longer
                  // eligible (added to allowlist/ignored/protected since).
                  if (
                        !$this->isAuditable($unit->item_key, $sourceValue)
                        || hash('sha256', (string) $sourceValue) !== $unit->source_hash
                  ) {
                        $queue->markResolved($unit);
                        $resolved++;
                        continue;
                  }

                  // Healed elsewhere (audit --repair, manual edit, --force run).
                  $current = $targetFlat[$unit->item_key] ?? null;

                  if ($current !== null && $current !== '' && $current !== $sourceValue) {
                        $queue->markResolved($unit);
                        $resolved++;
                        continue;
                  }

                  $toTranslate[$unit->item_key] = $sourceValue;
                  $unitsByKey[$unit->item_key] = $unit;
            }

            if (empty($toTranslate)) {
                  return [$resolved, $rescheduled];
            }

            try {
                  // force=true so a poisoned cache entry is evicted, not re-served.
                  $fixed = $translator->translateBatch($toTranslate, $locale, $sourceLocale, force: true);
            } catch (\Throwable $e) {
                  $this->warn("  ⚠️  {$fileName} → {$locale}: {$e->getMessage()}");

                  foreach ($unitsByKey as $unit) {
                        $queue->markAttemptFailed($unit, $e->getMessage());
                        $rescheduled++;
                  }

                  return [$resolved, $rescheduled];
            }

            $healedFlat = [];

            foreach ($unitsByKey as $key => $unit) {
                  $newValue = $fixed[$key] ?? $toTranslate[$key];

                  if (is_string($newValue) && $newValue !== '' && $newValue !== $toTranslate[$key]) {
                        $healedFlat[$key] = $newValue;
                        $queue->markResolved($unit);
                        $resolved++;
                  } else {
                        $queue->markAttemptFailed($unit, 'Re-translation still identical to source.');
                        $rescheduled++;
                  }
            }

            if (!empty($healedFlat)) {
                  // Base layer is the source file so structural drift is healed
                  // too; existing target translations win, then the fixes.
                  $finalFlat = array_merge($sourceFlat, $targetFlat, $healedFlat);

                  $nested = [];
                  foreach ($finalFlat as $key => $value) {
                        Arr::set($nested, $key, $value);
                  }

                  $files->saveToFile($fileName, $locale, $nested);

                  $this->line("  🔧 {$fileName} → {$locale}: " . count($healedFlat) . ' value(s) healed.');
            }

            return [$resolved, $rescheduled];
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Model units
      // ─────────────────────────────────────────────────────────────────────────

      /** @return bool  True when resolved, false when rescheduled. */
      protected function retryModelUnit(TranslationRetry $unit, RetryQueue $queue, TranslationService $translator): bool
      {
            // item_key = "{id}:{attribute}" — ids never contain ':' in practice;
            // limit 2 keeps attributes with colons intact regardless.
            [$id, $attribute] = array_pad(explode(':', $unit->item_key, 2), 2, null);
            $modelClass = $unit->target;

            // Stale: model class or record gone, attribute unreadable.
            if (
                  $attribute === null
                  || !class_exists($modelClass)
                  || !method_exists($modelClass, 'getTranslatableAttributes')
            ) {
                  $queue->markResolved($unit);
                  return true;
            }

            $record = $modelClass::withoutGlobalScopes()->whereKey($id)->first();

            if (!$record) {
                  $queue->markResolved($unit);
                  return true;
            }

            $translations = $record->getTranslations($attribute);
            $sourceValue = is_array($translations) ? ($translations['en'] ?? null) : null;

            // Stale: source changed/removed or no longer eligible.
            if (
                  !$this->isAuditable($attribute, $sourceValue)
                  || hash('sha256', (string) $sourceValue) !== $unit->source_hash
            ) {
                  $queue->markResolved($unit);
                  return true;
            }

            // Healed elsewhere.
            $current = $translations[$unit->locale] ?? null;

            if ($current !== null && $current !== '' && $current !== $sourceValue) {
                  $queue->markResolved($unit);
                  return true;
            }

            try {
                  $fixed = $translator->translate($sourceValue, $unit->locale, 'en', force: true);
            } catch (\Throwable $e) {
                  $queue->markAttemptFailed($unit, $e->getMessage());
                  return false;
            }

            if ($fixed === '' || $fixed === $sourceValue) {
                  $queue->markAttemptFailed($unit, 'Re-translation still identical to source.');
                  return false;
            }

            $translations[$unit->locale] = $fixed;
            $record->setTranslations($attribute, $translations);
            $record->saveQuietly();

            $queue->markResolved($unit);

            return true;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Helpers
      // ─────────────────────────────────────────────────────────────────────────

      /** @return array<string, mixed>|null */
      protected function loadFlat(string $locale, string $fileName): ?array
      {
            $path = lang_path("{$locale}/{$fileName}.php");

            if (!File::exists($path)) {
                  return null;
            }

            $data = File::getRequire($path);

            return is_array($data) ? Arr::dot($data) : null;
      }

      /**
       * Exhausted units need a human: exit non-zero so a scheduled run's
       * failure notification (or CI) surfaces them instead of silence.
       */
      protected function reportExhausted(RetryQueue $queue): int
      {
            $exhausted = $queue->exhaustedCount();

            if ($exhausted > 0) {
                  $this->error(
                        "🚨 {$exhausted} unit(s) exhausted their retry attempts and need review. "
                              . "Legitimately-identical values belong in lara-glot.audit.allowlist; "
                              . "inspect lara_glot_translation_retries.last_error for the rest."
                  );

                  return self::FAILURE;
            }

            return self::SUCCESS;
      }
}
