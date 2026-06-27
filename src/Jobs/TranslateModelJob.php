<?php

namespace Tonydev\LaraGlot\Jobs;

use Tonydev\LaraGlot\Services\SmartTranslationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranslateModelJob implements ShouldQueue
{
      // Batchable first — overrides fail() for batch-aware behaviour.
      use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

      /**
       * 4 attempts total: 1 initial + 3 retries.
       * Matches the backoff array which has 3 delay values.
       */
      public int $tries = 4;
      public int $timeout = 600;

      /**
       * @param string       $modelClass  Fully-qualified Eloquent model class name.
       * @param mixed        $modelId     Primary key value of the record to translate.
       * @param bool         $force       When true, re-translate even if translations exist.
       * @param array        $locales     Target locale codes (e.g. ['fr', 'de', 'es']).
       *                                 Falls back to all configured locales when empty.
       */
      public function __construct(
            public readonly string $modelClass,
            public readonly mixed $modelId,
            public readonly bool $force = false,
            public readonly array $locales = [],  // ✅ locales passed from LaraGlotManager
      ) {
      }

      public function handle(SmartTranslationService $translator): void
      {
            // Respect batch cancellation — bail early without recording a failure.
            if ($this->batch()?->cancelled()) {
                  return;
            }

            Log::info('🚀 [LaraGlot] Model job started', [
                  'model' => $this->modelClass,
                  'id' => $this->modelId,
                  'force' => $this->force,
                  'locales' => $this->locales,
            ]);

            $model = $this->modelClass::withoutGlobalScopes()->find($this->modelId);

            if (!$model) {
                  // Record was deleted between dispatch and processing — not a failure.
                  Log::warning('⚠️ [LaraGlot] Model not found — skipping', [
                        'class' => $this->modelClass,
                        'id' => $this->modelId,
                  ]);
                  return;
            }

            try {
                  // Pass $locales so the service knows which languages to target.
                  // When empty, SmartTranslationService falls back to all configured locales.
                  $translator->translateModel($model, $this->force, $this->locales);

                  Log::info('✅ [LaraGlot] Model job complete', [
                        'model' => $this->modelClass,
                        'id' => $this->modelId,
                  ]);

            } catch (\Throwable $e) {
                  Log::error('❌ [LaraGlot] Model job failed', [
                        'model' => $this->modelClass,
                        'id' => $this->modelId,
                        'error' => $e->getMessage(),
                  ]);

                  // Re-throw so Laravel records the failure on the batch
                  // and schedules the next retry attempt.
                  throw $e;
            }
      }

      /**
       * Progressive back-off: 30 s → 60 s → 120 s.
       * Matches $tries = 4 (1 initial attempt + 3 retries = 3 backoff values).
       */
      public function backoff(): array
      {
            return [30, 60, 120];
      }
}
