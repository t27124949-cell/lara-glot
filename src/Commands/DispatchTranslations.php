<?php

namespace Tonydev\LaraGlot\Commands;

use Illuminate\Console\Command;
use Tonydev\LaraGlot\Jobs\TranslateModelJob;

class DispatchTranslations extends Command
{
      /**
       * The name and signature of the console command.
       */
      protected $signature = 'laraglot:sync {model?} {--force : Force re-translation of all fields}';

      /**
       * The console command description.
       */
      protected $description = 'Scan and dispatch translation jobs for models needing updates';

      /**
       * Execute the console command.
       */
      public function handle()
      {
            $force = $this->option('force');
            $targetModel = $this->argument('model');
            $models = $targetModel ? [$targetModel] : config('lara-glot.models', []);

            if (empty($models)) {
                  $this->error("No models defined in lara-glot config, and no model argument provided.");
                  return;
            }

            foreach ($models as $modelClass) {
                  if (!class_exists($modelClass)) {
                        $this->warn("⚠️ Model [{$modelClass}] not found. Skipping.");
                        continue;
                  }

                  $query = $modelClass::withoutGlobalScopes();
                  $count = $query->count();

                  if ($count === 0) {
                        $this->info("ℹ️ No records found for {$modelClass}.");
                        continue;
                  }

                  $this->info("🔍 Syncing {$count} records for {$modelClass}:");

                  // We use the progress bar for UX and chunking for memory safety
                  $this->withProgressBar($count, function ($bar) use ($query, $modelClass, $force) {
                        $query->chunkById(100, function ($records) use ($bar, $modelClass, $force) {
                              foreach ($records as $record) {
                                    if ($force || $this->needsTranslation($record)) {
                                          TranslateModelJob::dispatch(
                                                $modelClass,
                                                $record->getKey(),
                                                $force
                                          )->onQueue(config('lara-glot.queue', 'translations'));
                                    }
                                    $bar->advance();
                              }
                        });
                  });

                  $this->newLine(2);
            }

            $this->info('🚀 All translation jobs have been dispatched to the queue.');
      }

      /**
       * Quick check to see if a model actually needs API work.
       */
      protected function needsTranslation($model): bool
      {
            if (!method_exists($model, 'getTranslatableAttributes')) {
                  return false;
            }

            $locales = array_keys(config('lara-glot.languages', ['en' => 'English']));

            foreach ($model->getTranslatableAttributes() as $field) {
                  $translations = $model->getTranslations($field) ?? [];

                  // Logic: If English exists, check if any other enabled locale is empty
                  if (!empty($translations['en'])) {
                        foreach ($locales as $locale) {
                              if ($locale !== 'en' && empty($translations[$locale])) {
                                    return true;
                              }
                        }
                  }
            }

            return false;
      }
}