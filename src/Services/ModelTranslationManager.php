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
 * This is used by:
 * - The Filament LaraGlotManager page
 * - Direct API calls
 * - Event listeners
 * - Any other part of the application that needs translations
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

      /**
       * Translate a single model synchronously (blocking).
       * Use this when you need results immediately in the current request.
       * 
       * @param Model $model
       * @param bool $force Force re-translation even if content exists
       * @return void
       */
      public function translateSync(Model $model, bool $force = false): void
      {
            Log::info("🔄 [LaraGlot] Sync translation started for " . class_basename($model) . " ID: {$model->id}");

            $this->translator->translateModel($model, $force);

            Log::info("✅ [LaraGlot] Sync translation complete for " . class_basename($model) . " ID: {$model->id}");
      }

      /**
       * Translate a single model asynchronously via queue.
       * Use this in Filament forms, after user saves, etc.
       * 
       * @param Model $model
       * @param bool $force Force re-translation
       * @param int $delay Seconds to delay before processing
       * @return void
       */
      public function translateAsync(Model $model, bool $force = false, int $delay = 0): void
      {
            Log::info("🚀 [LaraGlot] Async translation dispatched for " . class_basename($model) . " ID: {$model->id}");

            $job = new TranslateModelJob(
                  get_class($model),
                  $model->getKey(),
                  $force
            );

            if ($delay > 0) {
                  $job->delay($delay);
            }

            $job
                  ->onQueue(config('lara-glot.queue', 'translations'))
                  ->dispatch();
      }

      /**
       * Translate multiple model records as a batch.
       * Returns the batch ID so you can track progress.
       * 
       * @param Model[] $models Array of model instances
       * @param bool $force
       * @return string Batch ID
       */
      public function translateBatch(array $models, bool $force = false): string
      {
            if (empty($models)) {
                  throw new \InvalidArgumentException('At least one model must be provided');
            }

            $jobs = [];
            foreach ($models as $model) {
                  $jobs[] = new TranslateModelJob(
                        get_class($model),
                        $model->getKey(),
                        $force
                  );
            }

            $total = count($jobs);
            $queue = config('lara-glot.queue', 'translations');

            $batch = Bus::batch($jobs)
                  ->name('LaraGlot: ' . $total . ' model record(s)')
                  ->onQueue($queue)
                  ->allowFailures()
                  ->dispatch();

            Log::info("🚀 [LaraGlot] Batch dispatch: {$total} models, Batch ID: {$batch->id}");

            return $batch->id;
      }

      /**
       * Translate all records of a given model class.
       * Uses chunkById to handle large tables safely.
       * Returns the batch ID.
       * 
       * @param string $modelClass Full namespace (e.g., App\Models\Method)
       * @param bool $force
       * @param int $chunkSize How many records to process per chunk
       * @return string Batch ID
       */
      public function translateModelClass(string $modelClass, bool $force = false, int $chunkSize = 100): string
      {
            if (!class_exists($modelClass)) {
                  throw new \InvalidArgumentException("Model class not found: $modelClass");
            }

            $jobs = [];
            $count = 0;

            $modelClass::withoutGlobalScopes()->chunkById($chunkSize, function ($records) use ($modelClass, $force, &$jobs, &$count) {
                  foreach ($records as $record) {
                        $jobs[] = new TranslateModelJob($modelClass, $record->getKey(), $force);
                        $count++;
                  }
            });

            if (empty($jobs)) {
                  throw new \InvalidArgumentException("No records found in {$modelClass}");
            }

            $queue = config('lara-glot.queue', 'translations');

            $batch = Bus::batch($jobs)
                  ->name('LaraGlot: ' . $count . ' ' . class_basename($modelClass) . ' record(s)')
                  ->onQueue($queue)
                  ->allowFailures()
                  ->dispatch();

            Log::info("🚀 [LaraGlot] Batch dispatch: {$count} records from " . class_basename($modelClass) . ", Batch ID: {$batch->id}");

            return $batch->id;
      }

      /**
       * Translate multiple model classes.
       * Returns the batch ID.
       * 
       * @param array $modelClasses Array of fully-qualified class names
       * @param bool $force
       * @return string Batch ID
       */
      public function translateModelClasses(array $modelClasses, bool $force = false): string
      {
            if (empty($modelClasses)) {
                  throw new \InvalidArgumentException('At least one model class must be provided');
            }

            $jobs = [];
            $totalRecords = 0;

            foreach ($modelClasses as $modelClass) {
                  if (!class_exists($modelClass)) {
                        Log::warning("⚠️ [LaraGlot] Model class not found, skipping: $modelClass");
                        continue;
                  }

                  $modelClass::withoutGlobalScopes()->chunkById(100, function ($records) use ($modelClass, $force, &$jobs, &$totalRecords) {
                        foreach ($records as $record) {
                              $jobs[] = new TranslateModelJob($modelClass, $record->getKey(), $force);
                              $totalRecords++;
                        }
                  });
            }

            if (empty($jobs)) {
                  throw new \InvalidArgumentException("No records found in any of the provided model classes");
            }

            $queue = config('lara-glot.queue', 'translations');

            $batch = Bus::batch($jobs)
                  ->name('LaraGlot: ' . $totalRecords . ' record(s) from ' . count($modelClasses) . ' model(s)')
                  ->onQueue($queue)
                  ->allowFailures()
                  ->dispatch();

            Log::info("🚀 [LaraGlot] Batch dispatch: {$totalRecords} total records, Batch ID: {$batch->id}");

            return $batch->id;
      }

      /**
       * Check the status of a running batch.
       * 
       * @param string $batchId
       * @return array|null
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
                  'name' => $batch->name,
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
       * 
       * @param string $batchId
       * @return bool
       */
      public function cancelBatch(string $batchId): bool
      {
            $batch = Bus::findBatch($batchId);

            if (!$batch) {
                  return false;
            }

            $batch->cancel();
            Log::info("⏸️ [LaraGlot] Batch cancelled: $batchId");

            return true;
      }
}