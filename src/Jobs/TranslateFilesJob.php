<?php

namespace Tonydev\LaraGlot\Jobs;

use Tonydev\LaraGlot\Services\FileTranslationService;
use Illuminate\Bus\Batchable;           // ← gives $this->batch() and batch-aware behaviour
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranslateFilesJob implements ShouldQueue
{
      // Batchable must come FIRST — it overrides the fail() behaviour so that
      // a single job failure is recorded on the batch rather than killing it.
      use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

      public int $tries = 3;
      public int $timeout = 300;

      public function __construct(
            public readonly string $fileName,
            public readonly string $locale,
            public readonly bool $force = false,
      ) {
      }

      public function handle(FileTranslationService $service): void
      {
            // If the whole batch has been cancelled via the UI, bail early
            if ($this->batch()?->cancelled()) {
                  return;
            }

            Log::info('🚀 [LaraGlot] File job started', [
                  'file' => $this->fileName,
                  'locale' => $this->locale,
                  'force' => $this->force,
            ]);

            $targetPath = lang_path("{$this->locale}/{$this->fileName}.php");

            if (!$this->force && file_exists($targetPath)) {
                  Log::info('⏭️  [LaraGlot] Skipping (already exists)', [
                        'file' => $this->fileName,
                        'locale' => $this->locale,
                  ]);
                  // Counts as "processed" for the progress bar — not a failure
                  return;
            }

            try {
                  $service->translateFile($this->fileName, $this->locale);

                  Log::info('✅ [LaraGlot] File job complete', [
                        'file' => $this->fileName,
                        'locale' => $this->locale,
                  ]);
            } catch (\Throwable $e) {
                  Log::error('❌ [LaraGlot] File job failed', [
                        'file' => $this->fileName,
                        'locale' => $this->locale,
                        'error' => $e->getMessage(),
                  ]);
                  throw $e;   // Re-throw so Laravel records the failure on the batch
            }
      }

      public function backoff(): array
      {
            return [30, 60, 120];
      }
}
