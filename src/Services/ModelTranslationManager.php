<?php

namespace Tonydev\LaraGlot\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tonydev\LaraGlot\Jobs\TranslateModelJob;

/**
 * ModelTranslationManager
 *
 * A reusable service that handles model translation dispatch and processing.
 * Used by:
 *  - The Filament LaraGlotManager page
 *  - Direct API calls
 *  - Event listeners
 *  - Any other part of the application that needs translations
 *
 * No magic traits. No runtime flags. Just clean, explicit dispatch.
 */
class ModelTranslationManager
{
      protected SmartTranslationService $translator;

      public function __construct(SmartTranslationService $translator)
      {
            $this->translator = $translator;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Synchronous translation
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate a single model synchronously (blocking).
       * Use when you need results immediately in the current request.
       *
       * @param array $locales  Target locale codes. Empty = all configured locales.
       */
      public function translateSync(Model $model, bool $force = false, array $locales = []): void
      {
            // ✅ getKey() — works for any PK name/type, not just 'id'
            $label = class_basename($model) . ' ID: ' . $model->getKey();

            Log::info("🔄 [LaraGlot] Sync translation started for {$label}");

            $this->translator->translateModel($model, $force, $locales);

            Log::info("✅ [LaraGlot] Sync translation complete for {$label}");
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Asynchronous (single job) translation
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate a single model asynchronously via queue.
       * Use in Filament forms, after user saves, etc.
       *
       * @param array $locales  Target locale codes. Empty = all configured locales.
       * @param int   $delay    Seconds to delay before processing.
       */
      public function translateAsync(
            Model $model,
            bool $force = false,
            array $locales = [],
            int $delay = 0
      ): void {
            $label = class_basename($model) . ' ID: ' . $model->getKey();

            Log::info("🚀 [LaraGlot] Async translation dispatched for {$label}");

            $job = new TranslateModelJob(
                  get_class($model),
                  $model->getKey(),
                  $force,
                  $locales  // ✅ locales forwarded to job
            );

            if ($delay > 0) {
                  $job->delay($delay);
            }

            dispatch($job)->onQueue(config('lara-glot.queue', 'translations'));
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch translation — model instances
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate multiple model instances as a single batch.
       * Returns the batch ID for progress tracking, or null if no jobs were built.
       *
       * @param  Model[] $models
       * @param  array   $locales  Target locale codes. Empty = all configured locales.
       * @return string|null       Batch ID, or null if $models is empty.
       */
      public function translateBatch(
            array $models,
            bool $force = false,
            array $locales = []
      ): ?string {
            if (empty($models)) {
                  Log::warning('[LaraGlot] translateBatch() called with no models — nothing dispatched.');
                  return null;
            }

            $jobs = [];
            foreach ($models as $model) {
                  $jobs[] = new TranslateModelJob(
                        get_class($model),
                        $model->getKey(),
                        $force,
                        $locales  // ✅ locales forwarded
                  );
            }

            return $this->dispatchBatch($jobs, count($jobs) . ' model record(s)');
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch translation — single model class
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate all records of a given model class.
       * Uses chunkById to handle large tables safely.
       * Returns the batch ID, or null if the table is empty.
       *
       * @param  array $locales  Target locale codes. Empty = all configured locales.
       * @return string|null
       */
      public function translateModelClass(
            string $modelClass,
            bool $force = false,
            array $locales = [],
            int $chunkSize = 100
      ): ?string {
            if (!class_exists($modelClass)) {
                  throw new \InvalidArgumentException("Model class not found: {$modelClass}");
            }

            $jobs = [];
            $count = 0;

            $modelClass::withoutGlobalScopes()->chunkById(
                  $chunkSize,
                  function ($records) use ($modelClass, $force, $locales, &$jobs, &$count) {
                        foreach ($records as $record) {
                              $jobs[] = new TranslateModelJob(
                                    $modelClass,
                                    $record->getKey(),
                                    $force,
                                    $locales  // ✅ locales forwarded
                              );
                              $count++;
                        }
                  }
            );

            if (empty($jobs)) {
                  // Empty table is valid — not an error.
                  Log::info('[LaraGlot] No records found in ' . class_basename($modelClass) . ' — nothing dispatched.');
                  return null;
            }

            return $this->dispatchBatch(
                  $jobs,
                  "{$count} " . class_basename($modelClass) . ' record(s)'
            );
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch translation — multiple model classes
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate all records across multiple model classes in one batch.
       * Returns the batch ID, or null if no records were found.
       *
       * @param  string[] $modelClasses  Fully-qualified class names.
       * @param  array    $locales       Target locale codes. Empty = all configured locales.
       * @return string|null
       */
      public function translateModelClasses(
            array $modelClasses,
            bool $force = false,
            array $locales = []  // ✅ locales parameter — matches LaraGlotManager call
      ): ?string {
            if (empty($modelClasses)) {
                  Log::warning('[LaraGlot] translateModelClasses() called with no classes — nothing dispatched.');
                  return null;
            }

            $jobs = [];
            $totalRecords = 0;

            foreach ($modelClasses as $modelClass) {
                  if (!class_exists($modelClass)) {
                        Log::warning("[LaraGlot] Model class not found, skipping: {$modelClass}");
                        continue;
                  }

                  $modelClass::withoutGlobalScopes()->chunkById(
                        100,
                        function ($records) use ($modelClass, $force, $locales, &$jobs, &$totalRecords) {
                              foreach ($records as $record) {
                                    $jobs[] = new TranslateModelJob(
                                          $modelClass,
                                          $record->getKey(),
                                          $force,
                                          $locales  // ✅ locales forwarded
                                    );
                                    $totalRecords++;
                              }
                        }
                  );
            }

            if (empty($jobs)) {
                  // All tables empty — valid, not an error.
                  Log::info('[LaraGlot] No records found across provided model classes — nothing dispatched.');
                  return null;
            }

            return $this->dispatchBatch(
                  $jobs,
                  "{$totalRecords} record(s) from " . count($modelClasses) . ' model(s)'
            );
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Batch status & control
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Return a progress snapshot for the given batch ID.
       * Returns null if the batch has been pruned from the database.
       *
       * @return array{id: string, label: string, total: int, processed: int,
       *               failed: int, pending: int, progress: int,
       *               finished: bool, cancelled: bool}|null
       */
      public function getBatchStatus(string $batchId): ?array
      {
            $batch = Bus::findBatch($batchId);

            if (!$batch) {
                  return null;
            }

            $total = $batch->totalJobs;
            $processed = $batch->processedJobs();
            $failed = $batch->failedJobs;

            return [
                  'id' => $batch->id,
                  'label' => $batch->name,
                  'total' => $total,
                  'processed' => $processed,
                  'failed' => $failed,
                  'pending' => max(0, $total - $processed - $failed),
                  'progress' => $total > 0 ? (int) round(($processed / $total) * 100) : 0,
                  'finished' => $batch->finished(),
                  'cancelled' => $batch->cancelled(),
            ];
      }

      /**
       * Cancel a running batch.
       * Returns false if the batch no longer exists.
       */
      public function cancelBatch(string $batchId): bool
      {
            $batch = Bus::findBatch($batchId);

            if (!$batch) {
                  return false;
            }

            $batch->cancel();
            Log::info("⏸️ [LaraGlot] Batch cancelled: {$batchId}");

            return true;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Private helpers
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Wrap a jobs array in a Bus::batch() and dispatch it.
       * Centralises batch config so all dispatch paths are consistent.
       *
       * @param  object[] $jobs
       * @return string   Batch ID
       */
      private function dispatchBatch(array $jobs, string $label): string
      {
            $queue = config('lara-glot.queue', 'translations');

            $batch = Bus::batch($jobs)
                  ->name('LaraGlot: ' . $label)
                  ->onQueue($queue)
                  ->allowFailures()
                  ->dispatch();

            Log::info("🚀 [LaraGlot] Batch dispatched — {$label}, Batch ID: {$batch->id}");

            return $batch->id;
      }
}