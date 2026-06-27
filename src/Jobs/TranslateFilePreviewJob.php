<?php

namespace Tonydev\LaraGlot\Jobs;

use Tonydev\LaraGlot\Services\FileTranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranslateFilePreviewJob implements ShouldQueue
{
      use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

      /**
       * 3 attempts total: 1 initial + 2 retries.
       * Matches the backoff array which has 2 delay values.
       */
      public int $tries = 3;
      public int $timeout = 300;

      /**
       * @param string $fileName    Base name of the file (no extension, no path)
       * @param string $locale      Target locale code
       * @param string $previewKey  Unique cache key — the Livewire component polls on this
       */
      public function __construct(
            public readonly string $fileName,
            public readonly string $locale,
            public readonly string $previewKey,
      ) {
      }

      public function handle(FileTranslationService $service): void
      {
            Log::info('🔍 [LaraGlot] Preview job started', [
                  'file' => $this->fileName,
                  'locale' => $this->locale,
                  'previewKey' => $this->previewKey,
            ]);

            // Mark as "in progress" so the UI can distinguish
            // "not started" from "running" from "done".
            Cache::put("{$this->previewKey}:status", 'processing', now()->addMinutes(10));

            try {
                  $sourcePath = lang_path("en/{$this->fileName}.php");

                  if (!file_exists($sourcePath)) {
                        throw new \RuntimeException(
                              "Source file [en/{$this->fileName}.php] not found."
                        );
                  }

                  $originalData = Arr::dot(include $sourcePath);

                  // translateBatch() returns a keyed array (same keys as $originalData)
                  // with translated values — no array_combine needed.
                  $editedTranslations = $service->translateBatch($originalData, $this->locale);

                  // Store both arrays so the component can load them atomically.
                  // Status is written LAST so the component only sees 'done'
                  // once both data keys are already in cache.
                  Cache::put("{$this->previewKey}:original", $originalData, now()->addMinutes(30));
                  Cache::put("{$this->previewKey}:translations", $editedTranslations, now()->addMinutes(30));
                  Cache::put("{$this->previewKey}:status", 'done', now()->addMinutes(30));

                  Log::info('✅ [LaraGlot] Preview job complete', [
                        'file' => $this->fileName,
                        'locale' => $this->locale,
                        'keys' => count($originalData),
                  ]);

            } catch (\Throwable $e) {
                  // Write the error into cache so the UI can surface it.
                  Cache::put("{$this->previewKey}:status", 'failed', now()->addMinutes(10));
                  Cache::put("{$this->previewKey}:error", $e->getMessage(), now()->addMinutes(10));

                  Log::error('❌ [LaraGlot] Preview job failed', [
                        'file' => $this->fileName,
                        'locale' => $this->locale,
                        'error' => $e->getMessage(),
                  ]);

                  // Re-throw so Laravel can schedule the next retry attempt.
                  throw $e;
            }
      }

      /**
       * Progressive back-off: 30 s before retry 1, 60 s before retry 2.
       * Matches $tries = 3 (1 initial attempt + 2 retries = 2 backoff values).
       */
      public function backoff(): array
      {
            return [30, 60];
      }
}