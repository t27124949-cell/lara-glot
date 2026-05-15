<?php

namespace Tonydev\LaraGlot\Commands;
use Illuminate\Console\Command;

use Tonydev\LaraGlot\Services\ModelTranslationManager;

class DispatchTranslations extends Command
{
      protected $signature = 'laraglot:sync {model?} {--force : Force re-translation of all fields}';
      protected $description = 'Scan and dispatch translation jobs for models needing updates';

      protected ModelTranslationManager $manager;

      public function __construct(ModelTranslationManager $manager)
      {
            parent::__construct();
            $this->manager = $manager;
      }

      public function handle()
      {
            $force = $this->option('force');
            $targetModel = $this->argument('model');

            // 1. Resolve which models to process
            $models = $targetModel ? [$targetModel] : config('lara-glot.models', []);

            if (empty($models)) {
                  $this->error("No models defined in lara-glot config, and no model argument provided.");
                  return Command::FAILURE;
            }

            // 2. Dispatch using the Service
            $this->info("🚀 Starting translation dispatch via LaraGlot Manager...");

            try {
                  // We use the Manager's class-based dispatch
                  // This ensures logic like "withoutGlobalScopes" and "chunkById" is consistent
                  $batchId = $this->manager->translateModelClasses($models, $force);

                  $this->info("✅ Success! Batch [{$batchId}] created.");
                  $this->info("Jobs are now processing in the [" . config('lara-glot.queue', 'translations') . "] queue.");

                  return Command::SUCCESS;
            } catch (\Exception $e) {
                  $this->error("❌ Failed to dispatch: " . $e->getMessage());
                  return Command::FAILURE;
            }
      }
}