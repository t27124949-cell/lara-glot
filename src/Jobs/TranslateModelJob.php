<?php

namespace Tonydev\LaraGlot\Jobs;

// FIXED: Use the package namespace for the service
use Tonydev\LaraGlot\Services\SmartTranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranslateModelJob implements ShouldQueue
{
      use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

      /**
       * Using public properties allows Laravel to serialize the job data automatically.
       */
      public string $modelClass;
      public $modelId;
      public bool $force;

      /**
       * Package optimization: 10 minutes timeout is safe for large HTML blocks.
       */
      public int $tries = 3;
      public int $timeout = 600;

      public function __construct(string $modelClass, $modelId, bool $force = false)
      {
            $this->modelClass = $modelClass;
            $this->modelId = $modelId;
            $this->force = $force;
      }

      public function handle(SmartTranslationService $translator): void
      {
            Log::info("🚀 [LaraGlot] Translation Job Started", [
                  'model' => $this->modelClass,
                  'id' => $this->modelId,
                  'force' => $this->force
            ]);

            // Accessing model without global scopes is safer for background tasks
            $model = $this->modelClass::withoutGlobalScopes()->find($this->modelId);

            if (!$model) {
                  Log::warning("⚠️ [LaraGlot] Model not found", [
                        'class' => $this->modelClass,
                        'id' => $this->modelId
                  ]);
                  return;
            }

            try {
                  $translator->translateModel($model, $this->force);

                  Log::info("✅ [LaraGlot] Translation completed", [
                        'model' => $this->modelClass,
                        'id' => $this->modelId
                  ]);
            } catch (\Throwable $e) {
                  Log::error("❌ [LaraGlot] Translation Job Failed", [
                        'model' => $this->modelClass,
                        'id' => $this->modelId,
                        'error' => $e->getMessage()
                  ]);

                  throw $e; // This triggers the backoff retries below
            }
      }

      /**
       * Incremental backoff to handle temporary API rate limits or network blips.
       */
      public function backoff(): array
      {
            return [30, 60, 120];
      }
}