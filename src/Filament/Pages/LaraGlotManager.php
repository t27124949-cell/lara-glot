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
use Tonydev\LaraGlot\Services\ModelTranslationManager;
use UnitEnum;
use Filament\Support\Icons\Heroicon;

class LaraGlotManager extends Page
{
      protected static string|BackedEnum|null $navigationIcon = Heroicon::ShieldCheck;
      protected static UnitEnum|string|null $navigationGroup = 'Services';
      protected static ?string $slug = 'lara-glot-manager';

      // Must NOT be static — Filament v4/v5 declare Page::$view as an instance
      // property; redeclaring it static is a PHP fatal ("Cannot redeclare
      // non static Filament\Pages\Page::$view as static").
      protected string $view = 'lara-glot::filament.admin.pages.lara-glot-manager';

      // ─────────────────────────────────────────────────────────────────────────
      // Session keys
      // ─────────────────────────────────────────────────────────────────────────

      private const SESSION_FILE_BATCH = 'lara-glot.file_batch_id';
      private const SESSION_MODEL_BATCH = 'lara-glot.model_batch_id';

      // ─────────────────────────────────────────────────────────────────────────
      // Form state
      // All state lives in $this->data[] via statePath('data').
      // The individual public property declarations below have been removed —
      // they were shadowing the form state and were never actually read.
      // ─────────────────────────────────────────────────────────────────────────

      public ?array $data = [];

      // ─────────────────────────────────────────────────────────────────────────
      // Batch progress tracking
      // ─────────────────────────────────────────────────────────────────────────

      public ?string $fileBatchId = null;
      public ?array $fileBatchProgress = null;
      public ?string $modelBatchId = null;
      public ?array $modelBatchProgress = null;

      // ─────────────────────────────────────────────────────────────────────────
      // Preview state
      // ─────────────────────────────────────────────────────────────────────────

      public array $originalData = [];
      public array $editedTranslations = [];
      public ?string $previewFile = null;
      public ?string $previewLocale = null;
      public ?string $previewKey = null;
      public ?string $previewStatus = null;
      public ?string $previewError = null;

      // ─────────────────────────────────────────────────────────────────────────
      // Lifecycle
      // ─────────────────────────────────────────────────────────────────────────

      public function mount(): void
      {
            $this->form->fill();

            $this->restoreBatchFromSession(
                  self::SESSION_FILE_BATCH,
                  'fileBatchId',
                  'fileBatchProgress'
            );

            $this->restoreBatchFromSession(
                  self::SESSION_MODEL_BATCH,
                  'modelBatchId',
                  'modelBatchProgress'
            );
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

                        // ── SECTION 1: File Translation ───────────────────────────
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
                                                      ->helperText('Files from lang/' . $sourceLocale . '/ (exclusions applied)')
                                                      ->hintAction(
                                                            Action::make('clearFiles')
                                                                  ->label('Clear')
                                                                  ->icon('heroicon-m-x-mark')
                                                                  ->color('gray')
                                                                  ->action(fn($component) => $component->state([]))
                                                      )
                                                      ->suffixAction(
                                                            Action::make('selectAllFiles')
                                                                  ->label('Select All')
                                                                  ->icon('heroicon-m-check-circle')
                                                                  ->action(function ($component) use ($fileService) {
                                                                        $component->state(
                                                                              array_keys($fileService->getTranslatableFiles())
                                                                        );
                                                                  })
                                                      ),

                                                Select::make('fileLocales')
                                                      ->label('Target Language(s)')
                                                      ->options($targetLanguageOptions)
                                                      ->multiple()
                                                      ->native(false)
                                                      ->searchable()
                                                      ->placeholder('Select one or more languages…')
                                                      ->hintAction(
                                                            Action::make('clearFileLocales')
                                                                  ->label('Clear')
                                                                  ->icon('heroicon-m-x-mark')
                                                                  ->color('gray')
                                                                  ->action(fn($component) => $component->state([]))
                                                      )
                                                      ->suffixAction(
                                                            Action::make('selectAllFileLocales')
                                                                  ->label('Select All')
                                                                  ->icon('heroicon-m-check-circle')
                                                                  ->action(function ($component) use ($targetLanguageOptions) {
                                                                        $component->state(array_keys($targetLanguageOptions));
                                                                  })
                                                      ),
                                          ]),

                                    Toggle::make('fileForce')
                                          ->label('Force overwrite existing translation files')
                                          ->helperText('When on, files that already exist will be replaced.')
                                          ->default(false),
                              ]),

                        // ── SECTION 2: Model Translation ──────────────────────────
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
                                                      )
                                                      ->hintAction(
                                                            Action::make('clearModels')
                                                                  ->label('Clear')
                                                                  ->icon('heroicon-m-x-mark')
                                                                  ->color('gray')
                                                                  ->action(fn($component) => $component->state([]))
                                                      )
                                                      ->suffixAction(
                                                            Action::make('selectAllModels')
                                                                  ->label('Select All')
                                                                  ->icon('heroicon-m-check-circle')
                                                                  ->action(function ($component) use ($modelOptions) {
                                                                        $component->state(array_keys($modelOptions));
                                                                  })
                                                      ),

                                                Select::make('modelLocales')
                                                      ->label('Target Language(s)')
                                                      ->options($targetLanguageOptions)
                                                      ->multiple()
                                                      ->native(false)
                                                      ->searchable()
                                                      ->placeholder('Select one or more languages…')
                                                      ->hintAction(
                                                            Action::make('clearModelLocales')
                                                                  ->label('Clear')
                                                                  ->icon('heroicon-m-x-mark')
                                                                  ->color('gray')
                                                                  ->action(fn($component) => $component->state([]))
                                                      )
                                                      ->suffixAction(
                                                            Action::make('selectAllModelLocales')
                                                                  ->label('Select All')
                                                                  ->icon('heroicon-m-check-circle')
                                                                  ->action(function ($component) use ($targetLanguageOptions) {
                                                                        $component->state(array_keys($targetLanguageOptions));
                                                                  })
                                                      ),
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

            $jobs = [];
            foreach ($locales as $locale) {
                  foreach ($files as $fileName) {
                        $jobs[] = new TranslateFilesJob($fileName, $locale, $force);
                  }
            }

            $total = count($jobs);
            $queue = config('lara-glot.queue', 'translations');

            $batch = Bus::batch($jobs)
                  ->name('LaraGlot: ' . $total . ' file job(s)')
                  ->onQueue($queue)
                  ->allowFailures()
                  ->dispatch();

            $this->fileBatchId = $batch->id;
            $this->fileBatchProgress = $this->buildBatchProgress(
                  $batch,
                  count($files) . ' file(s) × ' . count($locales) . ' language(s)'
            );

            session([self::SESSION_FILE_BATCH => $batch->id]);

            Notification::make()
                  ->title("{$total} file job(s) dispatched!")
                  ->body(count($files) . ' file(s) × ' . count($locales) . ' language(s) — progress shown below.')
                  ->success()
                  ->send();
      }

      public function pollFileBatch(): void
      {
            if (!$this->fileBatchId) {
                  return;
            }

            $batch = Bus::findBatch($this->fileBatchId);

            if (!$batch) {
                  $this->fileBatchId = null;
                  $this->fileBatchProgress = null;
                  session()->forget(self::SESSION_FILE_BATCH);
                  return;
            }

            $this->fileBatchProgress = $this->buildBatchProgress($batch);

            if ($batch->finished()) {
                  $this->fileBatchId = null;
                  session()->forget(self::SESSION_FILE_BATCH);

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
                  $this->previewKey = null;

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
                  $this->previewKey = null;
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

      public function dispatchModelJobs(): void
      {
            $raw = $this->form->getRawState();
            $models = $raw['modelModels'] ?? [];
            $locales = $raw['modelLocales'] ?? []; // ✅ now passed to the manager
            $force = $raw['modelForce'] ?? false;

            if (empty($models)) {
                  Notification::make()
                        ->title('Please select at least one model.')
                        ->warning()
                        ->send();
                  return;
            }

            if (empty($locales)) {
                  Notification::make()
                        ->title('Please select at least one target language.')
                        ->warning()
                        ->send();
                  return;
            }

            try {
                  $manager = app(ModelTranslationManager::class);
                  $batchId = $manager->translateModelClasses($models, $force, $locales); // ✅ locales passed

                  $this->modelBatchId = $batchId;
                  $this->modelBatchProgress = $manager->getBatchStatus($batchId);
                  session([self::SESSION_MODEL_BATCH => $batchId]);

                  Notification::make()
                        ->title('Model translation batch dispatched!')
                        ->body('Batch ID: ' . $batchId)
                        ->success()
                        ->send();

            } catch (\Exception $e) {
                  Notification::make()
                        ->title('Error dispatching jobs')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
            }
      }

      public function pollModelBatch(): void
      {
            if (!$this->modelBatchId) {
                  return;
            }

            $manager = app(ModelTranslationManager::class);
            $status = $manager->getBatchStatus($this->modelBatchId);

            if (!$status) {
                  $this->modelBatchId = null;
                  $this->modelBatchProgress = null;
                  session()->forget(self::SESSION_MODEL_BATCH);
                  return;
            }

            $this->modelBatchProgress = $status;

            if ($status['finished']) {
                  $this->modelBatchId = null;
                  session()->forget(self::SESSION_MODEL_BATCH);

                  if ($status['failed'] > 0) {
                        Notification::make()
                              ->title('Batch finished with errors')
                              ->body($status['failed'] . ' job(s) failed.')
                              ->warning()
                              ->send();
                  } else {
                        Notification::make()
                              ->title('All translations complete! ✅')
                              ->success()
                              ->send();
                  }
            }
      }

      /**
       * Cancel an active batch.
       *
       * Uses Bus::findBatch()->cancel() directly — no need to route file batch
       * cancellations through ModelTranslationManager.
       */
      public function cancelBatch(string $type): void
      {
            $batchIdProp = $type === 'file' ? 'fileBatchId' : 'modelBatchId';
            $sessionKey = $type === 'file' ? self::SESSION_FILE_BATCH : self::SESSION_MODEL_BATCH;
            $batchId = $this->{$batchIdProp};

            if (!$batchId) {
                  return;
            }

            // ✅ Bus::findBatch() directly — no manager dependency for cancellation.
            Bus::findBatch($batchId)?->cancel();

            $this->{$batchIdProp} = null;
            session()->forget($sessionKey);

            Notification::make()
                  ->title('Batch cancelled')
                  ->body('Queued jobs that have not yet started will be skipped.')
                  ->warning()
                  ->send();
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Helpers
      // ─────────────────────────────────────────────────────────────────────────

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
                  'progress' => $total > 0 ? (int) round(($processed / $total) * 100) : 0,
                  'finished' => $batch->finished(),
                  'cancelled' => $batch->cancelled(),
            ];
      }

      private function restoreBatchFromSession(
            string $sessionKey,
            string $batchIdProp,
            string $progressProp
      ): void {
            $batchId = session($sessionKey);

            if (!$batchId) {
                  return;
            }

            $batch = Bus::findBatch($batchId);

            if (!$batch) {
                  session()->forget($sessionKey);
                  return;
            }

            $this->{$batchIdProp} = $batch->finished() ? null : $batchId;
            $this->{$progressProp} = $this->buildBatchProgress($batch);

            if ($batch->finished()) {
                  session()->forget($sessionKey);
            }
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
                              session([self::SESSION_FILE_BATCH => $batch->id]);

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
                              session([self::SESSION_FILE_BATCH => $batch->id]);

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
                                    if (!class_exists($modelClass)) {
                                          continue;
                                    }

                                    $modelClass::withoutGlobalScopes()->chunkById(
                                          100,
                                          function ($records) use ($modelClass, $targetLocales, &$jobs) {
                                                foreach ($records as $record) {
                                                      // ✅ $targetLocales now passed to every job
                                                      $jobs[] = new TranslateModelJob(
                                                            $modelClass,
                                                            $record->getKey(),
                                                            false,
                                                            $targetLocales
                                                      );
                                                }
                                          }
                                    );
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
                              session([self::SESSION_MODEL_BATCH => $batch->id]);

                              Notification::make()
                                    ->title(count($jobs) . ' model job(s) dispatched!')
                                    ->success()
                                    ->send();
                        }),
            ];
      }
}