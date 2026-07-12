<?php

namespace Tonydev\LaraGlot\Jobs;

use Tonydev\LaraGlot\Services\FileTranslationService;
use Illuminate\Bus\Batchable;
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

      /**
       * 4 attempts total: 1 initial + 3 retries.
       * Matches the backoff array which has 3 delay values.
       */
      public int $tries = 4;
      public int $timeout = 300;

      public function __construct(
            public readonly string $fileName,
            public readonly string $locale,
            public readonly bool $force = false,
      ) {
      }

      /**
       * Returns the fallback stats from FileTranslationService::translateFile()
       * (or null when the file was skipped) so `dispatch_sync()` callers — the
       * laraglot:files --sync command — can print translated-vs-fallback counts.
       */
      public function handle(FileTranslationService $service): ?array
      {
            // If the whole batch has been cancelled via the UI, bail early.
            if ($this->batch()?->cancelled()) {
                  return null;
            }

            Log::info('🚀 [LaraGlot] File job started', [
                  'file' => $this->fileName,
                  'locale' => $this->locale,
                  'force' => $this->force,
            ]);

            $targetPath = lang_path("{$this->locale}/{$this->fileName}.php");

            // Skip if the file already exists and force mode is off.
            // Returning here counts as "processed" for the batch progress bar — not a failure.
            if (!$this->force && file_exists($targetPath)) {
                  Log::info('⏭️  [LaraGlot] Skipping (already exists)', [
                        'file' => $this->fileName,
                        'locale' => $this->locale,
                  ]);
                  return null;
            }

            try {
                  $stats = $service->translateFile($this->fileName, $this->locale);

                  Log::info('✅ [LaraGlot] File job complete', [
                        'file' => $this->fileName,
                        'locale' => $this->locale,
                        'translated' => $stats['translated'],
                        'identical_to_source' => $stats['identical'],
                  ]);

                  return $stats;

            } catch (\Throwable $e) {
                  Log::error('❌ [LaraGlot] File job failed', [
                        'file' => $this->fileName,
                        'locale' => $this->locale,
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
