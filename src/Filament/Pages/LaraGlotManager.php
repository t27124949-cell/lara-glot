<?php

namespace Tonydev\LaraGlot\Filament\Pages;

use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tonydev\LaraGlot\Jobs\TranslateFilesJob;
use Tonydev\LaraGlot\Jobs\TranslateFilePreviewJob;
use Tonydev\LaraGlot\Jobs\TranslateModelJob;
use Tonydev\LaraGlot\Services\FileTranslationService;
use BackedEnum;
use UnitEnum;
use Filament\Support\Icons\Heroicon;

class LaraGlotManager extends Page
{
      protected static string|BackedEnum|null $navigationIcon = Heroicon::ShieldCheck;
      protected static UnitEnum|string|null $navigationGroup = 'Services';
      protected static ?string $slug = 'lara-glot-manager';
      protected string $view = 'lara-glot::filament.admin.pages.lara-glot-manager';

      // ─────────────────────────────────────────────────────────────────────────
      // Form state — FILE section
      // Completely separate field names from the model section to prevent
      // Filament from cross-validating between the two groups.
      // ─────────────────────────────────────────────────────────────────────────

      public array $fileFiles = [];
      public array $fileLocales = [];
      public bool $fileForce = false;

      // ─────────────────────────────────────────────────────────────────────────
      // Form state — MODEL section
      // ─────────────────────────────────────────────────────────────────────────

      public array $modelModels = [];
      public array $modelLocales = [];
      public bool $modelForce = false;

      // ─────────────────────────────────────────────────────────────────────────
      // Batch progress tracking
      //
      // We track two independent batches — one for file jobs, one for model jobs.
      // Each has its own poll loop so they can run concurrently.
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Laravel batch ID for the active file translation batch.
       * Null = no batch running → Blade stops polling automatically.
       */
      public ?string $fileBatchId = null;

      /**
       * Snapshot of the file batch progress for the Blade template.
       * Updated by pollFileBatch() every 3 seconds.
       *
       * Shape: ['total' => int, 'processed' => int, 'failed' => int,
       *         'progress' => int (0-100), 'finished' => bool, 'label' => string]
       */
      public ?array $fileBatchProgress = null;

      /** Laravel batch ID for the active model translation batch. */
      public ?string $modelBatchId = null;

      /** Same shape as $fileBatchProgress. */
      public ?array $modelBatchProgress = null;

      // ─────────────────────────────────────────────────────────────────────────
      // Preview state
      // ─────────────────────────────────────────────────────────────────────────

      public array $originalData = [];
      public array $editedTranslations = [];
      public ?string $previewFile = null;
      public ?string $previewLocale = null;
      public ?string $previewKey = null;
      public ?string $previewStatus = null;   // null | processing | done | failed
      public ?string $previewError = null;

      public ?array $data = [];

      // ─────────────────────────────────────────────────────────────────────────
      // Lifecycle
      // ─────────────────────────────────────────────────────────────────────────

      public function mount(): void
      {
            $this->form->fill();
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Form
      // ─────────────────────────────────────────────────────────────────────────

      public function form(Schema $schema): Schema
      {
            $fileService = app(FileTranslationService::class);
            $sourceLocale = config('lara-glot.source_locale', 'en');

            $targetLanguageOptions = collect(config('lara-glot.languages', []))
                  ->mapWithKeys(fn($lang, $key) => [$key => $lang['name']])
                  ->reject(fn($name, $key) => $key === $sourceLocale)
                  ->toArray();

            $modelOptions = collect(config('lara-glot.models', []))
                  ->mapWithKeys(fn($class) => [$class => class_basename($class)])
                  ->toArray();

            return $schema
                  ->statePath('data')
                  ->components([

                        // ── SECTION 1: File Translation ───────────────────────
                        Section::make('📄 File Translation')
                              ->description(
                                    'Translate PHP language files from lang/en/ to any configured locale. '
                                    . 'Use "AI Preview" to review before saving, or "Dispatch Jobs" for immediate background processing.'
                              )
                              ->schema([
                                    Grid::make(2)
                                          ->schema([
                                                Select::make('fileFiles')
                                                      ->label('Source File(s)')
                                                      ->options($fileService->getTranslatableFiles())
                                                      ->multiple()
                                                      ->native(false)
                                                      ->searchable()
                                                      ->placeholder('Select one or more files…')
                                                      ->helperText('Files from lang/' . $sourceLocale . '/ (exclusions applied)'),
                                                Select::make('fileLocales')
                                                      ->label('Target Language(s)')
                                                      ->options($targetLanguageOptions)
                                                      ->multiple()
                                                      ->native(false)
                                                      ->searchable()
                                                      ->placeholder('Select one or more languages…')
                                                      ->hintAction(
                                                            Action::make('clear')
                                                                  ->label('Clear')
                                                                  ->icon('heroicon-m-x-mark')
                                                                  ->color('gray')
                                                                  ->action(fn($component) => $component->state([]))
                                                      )
                                                      ->suffixAction(
                                                            Action::make('selectAll')
                                                                  ->label('Select All')
                                                                  ->icon('heroicon-m-check-circle')
                                                                  ->action(function ($component) use ($targetLanguageOptions) {
                                                                        $component->state(array_keys($targetLanguageOptions));
                                                                  })
                                                      )
                                          ]),

                                    Toggle::make('fileForce')
                                          ->label('Force overwrite existing translation files')
                                          ->helperText('When on, files that already exist will be replaced.')
                                          ->default(false),
                              ]),

                        // ── SECTION 2: Model Translation ─────────────────────
                        Section::make('🗄️ Model Translation')
                              ->description(
                                    'Dispatch background jobs to translate Eloquent model records. '
                                    . 'Each record is queued individually — large tables never time out. '
                                    . 'Models must use HasTranslations and be registered in config/lara-glot.php.'
                              )
                              ->schema([
                                    Grid::make(2)
                                          ->schema([
                                                Select::make('modelModels')
                                                      ->label('Model(s)')
                                                      ->options($modelOptions)
                                                      ->multiple()
                                                      ->native(false)
                                                      ->searchable()
                                                      ->placeholder('Select models to translate…')
                                                      ->helperText(
                                                            empty($modelOptions)
                                                            ? 'No models registered — add them to config/lara-glot.php'
                                                            : 'Records are chunked 100 at a time'
                                                      ),

                                                Select::make('modelLocales')
                                                      ->label('Target Language(s)')
                                                      ->options($targetLanguageOptions)
                                                      ->multiple()
                                                      ->native(false)
                                                      ->searchable()
                                                      ->placeholder('Select one or more languages…'),
                                          ]),

                                    Toggle::make('modelForce')
                                          ->label('Force re-translate existing content')
                                          ->helperText('When on, records that already have translations will be re-translated.')
                                          ->default(false),
                              ]),
                  ]);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // File translation — dispatch
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Build one Bus::batch() containing one TranslateFilesJob per file × locale.
       * The batch ID is stored in $fileBatchId so Blade can poll the progress.
       */
      public function dispatchFileJobs(): void
      {
            $raw = $this->form->getRawState();
            $files = $raw['fileFiles'] ?? [];
            $locales = $raw['fileLocales'] ?? [];
            $force = $raw['fileForce'] ?? false;

            if (empty($files) || empty($locales)) {
                  Notification::make()
                        ->title('Please select at least one file and one target language.')
                        ->warning()
                        ->send();
                  return;
            }

            // Build the flat job list
            $jobs = [];
            foreach ($locales as $locale) {
                  foreach ($files as $fileName) {
                        $jobs[] = new TranslateFilesJob($fileName, $locale, $force);
                  }
            }

            $total = count($jobs);
            $queue = config('lara-glot.queue', 'translations');

            // Dispatch as a named batch so we can look it up by ID
            $batch = Bus::batch($jobs)
                  ->name('LaraGlot: ' . $total . ' file job(s)')
                  ->onQueue($queue)
                  ->allowFailures()   // One failed job doesn't cancel the rest
                  ->dispatch();

            // Store the batch ID — Blade will poll pollFileBatch() every 3 s
            $this->fileBatchId = $batch->id;
            $this->fileBatchProgress = $this->buildBatchProgress($batch, count($files) . ' file(s) × ' . count($locales) . ' language(s)');

            Notification::make()
                  ->title("{$total} file job(s) dispatched!")
                  ->body(count($files) . ' file(s) × ' . count($locales) . ' language(s) — progress shown below.')
                  ->success()
                  ->send();
      }

      /**
       * Called by wire:poll.3000ms in Blade while $fileBatchId is set.
       * Reads the batch from the database and updates $fileBatchProgress.
       */
      public function pollFileBatch(): void
      {
            if (!$this->fileBatchId) {
                  return;
            }

            $batch = Bus::findBatch($this->fileBatchId);

            if (!$batch) {
                  // Batch record cleaned up — stop polling
                  $this->fileBatchId = null;
                  $this->fileBatchProgress = null;
                  return;
            }

            $this->fileBatchProgress = $this->buildBatchProgress($batch);

            // Stop polling once complete or cancelled
            if ($batch->finished()) {
                  $this->fileBatchId = null;

                  $failedCount = $batch->failedJobs;
                  if ($failedCount > 0) {
                        Notification::make()
                              ->title('File batch finished with errors')
                              ->body("{$failedCount} job(s) failed. Check storage/logs/laravel.log for details.")
                              ->warning()
                              ->send();
                  } else {
                        Notification::make()
                              ->title('All file translations complete! ✅')
                              ->success()
                              ->send();
                  }
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // File translation — preview
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Dispatch a single TranslateFilePreviewJob (not batched — it's one job).
       * The Blade polls checkPreviewStatus() every 3 s until the cache is written.
       */
      public function generatePreview(): void
      {
            $raw = $this->form->getRawState();
            $files = $raw['fileFiles'] ?? [];
            $locales = $raw['fileLocales'] ?? [];

            if (empty($files) || empty($locales)) {
                  Notification::make()
                        ->title('Please select at least one file and one target language.')
                        ->warning()
                        ->send();
                  return;
            }

            $fileName = $files[0];
            $locale = $locales[0];

            if (!file_exists(lang_path("en/{$fileName}.php"))) {
                  Notification::make()
                        ->title("Source file not found: en/{$fileName}.php")
                        ->danger()
                        ->send();
                  return;
            }

            if (count($files) > 1 || count($locales) > 1) {
                  Notification::make()
                        ->title('Preview limited to first selection')
                        ->body("Showing \"{$fileName}\" → {$locale}. Use Dispatch Jobs to process all selections.")
                        ->info()
                        ->send();
            }

            $this->resetPreview();

            $this->previewKey = 'lara-glot.preview.' . Str::uuid();
            $this->previewFile = $fileName;
            $this->previewLocale = $locale;
            $this->previewStatus = 'processing';

            TranslateFilePreviewJob::dispatch($fileName, $locale, $this->previewKey)
                  ->onQueue(config('lara-glot.queue', 'translations'));

            Notification::make()
                  ->title('Preview started!')
                  ->body("Translating \"{$fileName}\" → {$locale}. The review table will appear automatically.")
                  ->success()
                  ->send();
      }

      /**
       * Polls the cache for preview job completion. Called by wire:poll in Blade.
       */
      public function checkPreviewStatus(): void
      {
            if (!$this->previewKey) {
                  return;
            }

            $status = Cache::get("{$this->previewKey}:status");

            if ($status === 'done') {
                  $this->originalData = Cache::get("{$this->previewKey}:original", []);
                  $this->editedTranslations = Cache::get("{$this->previewKey}:translations", []);
                  $this->previewStatus = 'done';
                  Cache::forget("{$this->previewKey}:status");
                  $this->previewKey = null;   // Stop polling

                  Notification::make()
                        ->title('Preview ready!')
                        ->body('Review and edit below, then click Save.')
                        ->success()
                        ->send();
                  return;
            }

            if ($status === 'failed') {
                  $failedKey = $this->previewKey;
                  $this->previewError = Cache::get("{$failedKey}:error", 'An unknown error occurred.');
                  $this->previewStatus = 'failed';
                  $this->previewKey = null;   // Stop polling
                  Cache::forget("{$failedKey}:status");
                  Cache::forget("{$failedKey}:error");

                  Notification::make()
                        ->title('Preview failed')
                        ->body($this->previewError)
                        ->danger()
                        ->send();
            }
      }

      public function savePreview(): void
      {
            if (empty($this->editedTranslations) || !$this->previewFile || !$this->previewLocale) {
                  Notification::make()
                        ->title('Nothing to save — generate a preview first.')
                        ->warning()
                        ->send();
                  return;
            }

            $nestedData = [];
            foreach ($this->editedTranslations as $key => $value) {
                  Arr::set($nestedData, $key, $value);
            }

            app(FileTranslationService::class)->saveToFile(
                  $this->previewFile,
                  $this->previewLocale,
                  $nestedData
            );

            Notification::make()
                  ->title("Saved: lang/{$this->previewLocale}/{$this->previewFile}.php")
                  ->success()
                  ->send();

            $this->resetPreview();
      }

      public function resetPreview(): void
      {
            $this->originalData = [];
            $this->editedTranslations = [];
            $this->previewFile = null;
            $this->previewLocale = null;
            $this->previewKey = null;
            $this->previewStatus = null;
            $this->previewError = null;
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Model translation — dispatch
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Chunk through every selected model's records and build one Bus::batch().
       * Uses chunkById(100) to avoid loading entire tables into memory.
       */
      public function dispatchModelJobs(): void
      {
            $raw = $this->form->getRawState();
            $models = $raw['modelModels'] ?? [];
            $force = $raw['modelForce'] ?? false;

            if (empty($models)) {
                  Notification::make()
                        ->title('Please select at least one model.')
                        ->warning()
                        ->send();
                  return;
            }

            $jobs = [];
            $queue = config('lara-glot.queue', 'translations');

            foreach ($models as $modelClass) {
                  if (!class_exists($modelClass)) {
                        Notification::make()
                              ->title("Model not found: {$modelClass}")
                              ->warning()
                              ->send();
                        continue;
                  }

                  // chunkById is memory-safe for large tables
                  $modelClass::withoutGlobalScopes()->chunkById(
                        100,
                        function ($records) use ($modelClass, $force, &$jobs) {
                              foreach ($records as $record) {
                                    $jobs[] = new TranslateModelJob($modelClass, $record->getKey(), $force);
                              }
                        }
                  );
            }

            if (empty($jobs)) {
                  Notification::make()
                        ->title('No records found in the selected model(s).')
                        ->info()
                        ->send();
                  return;
            }

            $total = count($jobs);

            $batch = Bus::batch($jobs)
                  ->name('LaraGlot: ' . $total . ' model record(s)')
                  ->onQueue($queue)
                  ->allowFailures()
                  ->dispatch();

            $this->modelBatchId = $batch->id;
            $this->modelBatchProgress = $this->buildBatchProgress(
                  $batch,
                  count($models) . ' model(s) · ' . $total . ' record(s)'
            );

            Notification::make()
                  ->title("{$total} model job(s) dispatched!")
                  ->body(count($models) . ' model(s) · ' . $total . ' record(s) — progress shown below.')
                  ->success()
                  ->send();
      }

      /**
       * Called by wire:poll.3000ms while $modelBatchId is set.
       */
      public function pollModelBatch(): void
      {
            if (!$this->modelBatchId) {
                  return;
            }

            $batch = Bus::findBatch($this->modelBatchId);

            if (!$batch) {
                  $this->modelBatchId = null;
                  $this->modelBatchProgress = null;
                  return;
            }

            $this->modelBatchProgress = $this->buildBatchProgress($batch);

            if ($batch->finished()) {
                  $this->modelBatchId = null;

                  $failedCount = $batch->failedJobs;
                  if ($failedCount > 0) {
                        Notification::make()
                              ->title('Model batch finished with errors')
                              ->body("{$failedCount} job(s) failed. Check storage/logs/laravel.log for details.")
                              ->warning()
                              ->send();
                  } else {
                        Notification::make()
                              ->title('All model translations complete! ✅')
                              ->success()
                              ->send();
                  }
            }
      }

      /**
       * Cancel a running batch. Works for both file and model batches.
       */
      public function cancelBatch(string $type): void
      {
            $batchIdProp = $type === 'file' ? 'fileBatchId' : 'modelBatchId';
            $progressProp = $type === 'file' ? 'fileBatchProgress' : 'modelBatchProgress';

            $batchId = $this->{$batchIdProp};
            if (!$batchId)
                  return;

            $batch = Bus::findBatch($batchId);
            $batch?->cancel();

            $this->{$batchIdProp} = null;
            $this->{$progressProp} = null;

            Notification::make()
                  ->title('Batch cancelled')
                  ->body('Queued jobs that have not yet started will be skipped.')
                  ->warning()
                  ->send();
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Helpers
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Convert a Laravel Batch object into a simple array the Blade can use
       * without needing to know anything about the Batch class.
       */
      protected function buildBatchProgress(Batch $batch, string $label = ''): array
      {
            $total = $batch->totalJobs;
            $processed = $batch->processedJobs();
            $failed = $batch->failedJobs;

            return [
                  'id' => $batch->id,
                  'label' => $label ?: $batch->name,
                  'total' => $total,
                  'processed' => $processed,
                  'failed' => $failed,
                  'pending' => max(0, $total - $processed - $failed),
                  // Integer 0-100 for the <progress> element
                  'progress' => $total > 0 ? (int) round(($processed / $total) * 100) : 0,
                  'finished' => $batch->finished(),
                  'cancelled' => $batch->cancelled(),
            ];
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Header actions
      // ─────────────────────────────────────────────────────────────────────────

      protected function getHeaderActions(): array
      {
            $fileService = app(FileTranslationService::class);
            $sourceLocale = config('lara-glot.source_locale', 'en');
            $queue = config('lara-glot.queue', 'translations');

            $targetLocales = collect(config('lara-glot.languages', []))
                  ->keys()
                  ->reject(fn($l) => $l === $sourceLocale)
                  ->values()
                  ->all();

            return [

                  Action::make('syncAllFiles')
                        ->label('Sync All Files')
                        ->icon('heroicon-m-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Sync All Language Files')
                        ->modalDescription('Dispatches one job per file × language. Existing files are skipped. Queue: [' . $queue . '].')
                        ->modalSubmitActionLabel('Yes, Dispatch All')
                        ->action(function () use ($fileService, $targetLocales, $queue) {
                              $files = array_keys($fileService->getTranslatableFiles());
                              if (empty($targetLocales) || empty($files)) {
                                    Notification::make()->title('Nothing to sync')->warning()->send();
                                    return;
                              }

                              $jobs = [];
                              foreach ($targetLocales as $locale) {
                                    foreach ($files as $fileName) {
                                          $jobs[] = new TranslateFilesJob($fileName, $locale, false);
                                    }
                              }

                              $batch = Bus::batch($jobs)
                                    ->name('LaraGlot: sync all files (' . count($jobs) . ' jobs)')
                                    ->onQueue($queue)
                                    ->allowFailures()
                                    ->dispatch();

                              $this->fileBatchId = $batch->id;
                              $this->fileBatchProgress = $this->buildBatchProgress($batch);

                              Notification::make()
                                    ->title(count($jobs) . ' file job(s) dispatched!')
                                    ->body('Progress shown on the page.')
                                    ->success()
                                    ->send();
                        }),

                  Action::make('forceSyncAllFiles')
                        ->label('Force Re-translate Files')
                        ->icon('heroicon-m-arrow-path')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Force Re-translate All Files')
                        ->modalDescription('Overwrites EVERY existing translation file for every configured language.')
                        ->modalSubmitActionLabel('Yes, Re-translate Everything')
                        ->action(function () use ($fileService, $targetLocales, $queue) {
                              $files = array_keys($fileService->getTranslatableFiles());
                              $jobs = [];
                              foreach ($targetLocales as $locale) {
                                    foreach ($files as $fileName) {
                                          $jobs[] = new TranslateFilesJob($fileName, $locale, true);
                                    }
                              }

                              $batch = Bus::batch($jobs)
                                    ->name('LaraGlot: force all files (' . count($jobs) . ' jobs)')
                                    ->onQueue($queue)
                                    ->allowFailures()
                                    ->dispatch();

                              $this->fileBatchId = $batch->id;
                              $this->fileBatchProgress = $this->buildBatchProgress($batch);

                              Notification::make()
                                    ->title(count($jobs) . ' file job(s) dispatched (force mode)!')
                                    ->success()
                                    ->send();
                        }),

                  Action::make('syncAllModels')
                        ->label('Sync All Models')
                        ->icon('heroicon-m-circle-stack')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Sync All Registered Models')
                        ->modalDescription('Dispatches one job per record for every model in config/lara-glot.php. Already-translated records are skipped.')
                        ->modalSubmitActionLabel('Yes, Dispatch All Model Jobs')
                        ->action(function () use ($targetLocales, $queue) {
                              $models = config('lara-glot.models', []);
                              if (empty($models) || empty($targetLocales)) {
                                    Notification::make()->title('Nothing to sync')->warning()->send();
                                    return;
                              }

                              $jobs = [];
                              foreach ($models as $modelClass) {
                                    if (!class_exists($modelClass))
                                          continue;
                                    $modelClass::withoutGlobalScopes()->chunkById(100, function ($records) use ($modelClass, &$jobs) {
                                          foreach ($records as $record) {
                                                $jobs[] = new TranslateModelJob($modelClass, $record->getKey(), false);
                                          }
                                    });
                              }

                              if (empty($jobs)) {
                                    Notification::make()->title('No records found')->info()->send();
                                    return;
                              }

                              $batch = Bus::batch($jobs)
                                    ->name('LaraGlot: sync all models (' . count($jobs) . ' records)')
                                    ->onQueue($queue)
                                    ->allowFailures()
                                    ->dispatch();

                              $this->modelBatchId = $batch->id;
                              $this->modelBatchProgress = $this->buildBatchProgress($batch);

                              Notification::make()
                                    ->title(count($jobs) . ' model job(s) dispatched!')
                                    ->success()
                                    ->send();
                        }),
            ];
      }
}
