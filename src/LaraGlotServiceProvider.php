<?php

namespace Tonydev\LaraGlot;

use Illuminate\Support\ServiceProvider;
use Tonydev\LaraGlot\Commands\DispatchTranslations;
use Tonydev\LaraGlot\Commands\TranslateFilesCommand;
use Tonydev\LaraGlot\Services\FileTranslationService;
use Tonydev\LaraGlot\Services\ModelTranslationManager;
use Tonydev\LaraGlot\Services\SmartTranslationService;
use Tonydev\LaraGlot\Services\TranslationService;

class LaraGlotServiceProvider extends ServiceProvider
{
      /**
       * Register services in the container.
       *
       * All services are singletons — drivers hold in-process caches and
       * config that should not be re-initialised per-request.
       */
      public function register(): void
      {
            // Merge package config so it is available before boot().
            $this->mergeConfigFrom(
                  __DIR__ . '/../config/lara-glot.php',
                  'lara-glot'
            );

            // Core translation service — resolves the configured driver internally.
            $this->app->singleton(
                  TranslationService::class,
                  fn() => new TranslationService()
            );

            // File translation service — wraps TranslationService with file I/O.
            $this->app->singleton(
                  FileTranslationService::class,
                  fn($app) => new FileTranslationService(
                        $app->make(TranslationService::class)
                  )
            );

            // Smart translation service — handles Eloquent model attribute translation.
            $this->app->singleton(
                  SmartTranslationService::class,
                  fn($app) => new SmartTranslationService(
                        $app->make(TranslationService::class)
                  )
            );

            // Model translation manager — batch dispatch and progress tracking.
            $this->app->singleton(
                  ModelTranslationManager::class,
                  fn($app) => new ModelTranslationManager(
                        $app->make(SmartTranslationService::class)
                  )
            );
      }

      /**
       * Bootstrap package services.
       */
      public function boot(): void
      {
            // ── Views — always loaded so Filament pages render correctly ──────────
            $this->loadViewsFrom(
                  __DIR__ . '/../resources/views',
                  'lara-glot'
            );

            // ── Console-only: publishables + Artisan commands ─────────────────────
            if ($this->app->runningInConsole()) {

                  // php artisan vendor:publish --tag=lara-glot-config
                  $this->publishes([
                        __DIR__ . '/../config/lara-glot.php' => config_path('lara-glot.php'),
                  ], 'lara-glot-config');

                  // php artisan vendor:publish --tag=lara-glot-views
                  $this->publishes([
                        __DIR__ . '/../resources/views' => resource_path('views/vendor/lara-glot'),
                  ], 'lara-glot-views');

                  $this->commands([
                        DispatchTranslations::class,
                        TranslateFilesCommand::class,
                  ]);
            }
      }
}