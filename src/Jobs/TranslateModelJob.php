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
      use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

      public int $tries = 3;
      public int $timeout = 600;

      public function __construct(
            public readonly string $modelClass,
            public readonly mixed $modelId,
            public readonly bool $force = false,
      ) {
      }

      public function handle(SmartTranslationService $translator): void
      {
            // Respect batch cancellation
            if ($this->batch()?->cancelled()) {
                  return;
            }

            Log::info('🚀 [LaraGlot] Model job started', [
                  'model' => $this->modelClass,
                  'id' => $this->modelId,
                  'force' => $this->force,
            ]);

            $model = $this->modelClass::withoutGlobalScopes()->find($this->modelId);

            if (!$model) {
                  Log::warning('⚠️ [LaraGlot] Model not found — skipping', [
                        'class' => $this->modelClass,
                        'id' => $this->modelId,
                  ]);
                  // Not a failure — record was likely deleted between dispatch and processing
                  return;
            }

            try {
                  $translator->translateModel($model, $this->force);

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
                  throw $e;
            }
      }

      public function backoff(): array
      {
            return [30, 60, 120];
      }
}
