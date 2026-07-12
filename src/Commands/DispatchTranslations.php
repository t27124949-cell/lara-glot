<?php

namespace Tonydev\LaraGlot\Commands;

use Illuminate\Console\Command;
use Tonydev\LaraGlot\Services\ModelTranslationManager;

class DispatchTranslations extends Command
{
      protected $signature = 'laraglot:sync
                              {model?             : Fully-qualified model class to translate (e.g. "App\\Models\\Page")}
                              {--force            : Force re-translation of all fields, ignoring change detection}
                              {--locale=*         : Only translate to this locale (repeatable: --locale=fr --locale=de)}';

      protected $description = 'Scan and dispatch translation jobs for models needing updates';

      // NOTE: inject via handle(), not the constructor — Artisan instantiates
      // every registered command on EVERY artisan invocation, so constructor
      // injection would drag the whole translation service chain (including
      // the configured driver) into `php artisan migrate` and friends.
      public function handle(ModelTranslationManager $manager): int
      {
            $force = (bool) $this->option('force');
            $targetModel = $this->argument('model');
            $locales = (array) $this->option('locale'); // empty = all configured locales

            // ── Resolve which models to process ──────────────────────────────────
            $models = $targetModel
                  ? [$targetModel]
                  : config('lara-glot.models', []);

            if (empty($models)) {
                  $this->error('No models defined in config/lara-glot.php and no model argument provided.');
                  return self::FAILURE;
            }

            $this->info('Starting translation dispatch via LaraGlot…');

            if (!empty($locales)) {
                  $this->line('Target locales: ' . implode(', ', $locales));
            } else {
                  $this->line('Target locales: all configured');
            }

            try {
                  $batchId = $manager->translateModelClasses($models, $force, $locales);

                  if ($batchId === null) {
                        $this->warn('No records found in the specified model(s) — nothing dispatched.');
                        return self::SUCCESS;
                  }

                  $this->info("Batch [{$batchId}] created.");
                  $this->line('Jobs are now processing on the [' . config('lara-glot.queue', 'translations') . '] queue.');

                  return self::SUCCESS;

            } catch (\Exception $e) {
                  $this->error('Failed to dispatch: ' . $e->getMessage());
                  return self::FAILURE;
            }
      }
}
