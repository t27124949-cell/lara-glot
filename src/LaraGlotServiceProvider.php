<?php

namespace Tonydev\LaraGlot;

use Illuminate\Support\ServiceProvider;
use Tonydev\LaraGlot\Commands\DispatchTranslations;
use Tonydev\LaraGlot\Services\SmartTranslationService;
use Tonydev\LaraGlot\Services\TranslationService;

class LaraGlotServiceProvider extends ServiceProvider
{
      /**
       * Register services in the container.
       */
      public function register(): void
      {
            // Use 'lara-glot' as the unique config key
            $this->mergeConfigFrom(__DIR__ . '/../config/lara-glot.php', 'lara-glot');

            // Register the base Translation API service
            $this->app->singleton(TranslationService::class, function ($app) {
                  return new TranslationService();
            });

            // Register the Smart Logic service (injecting the base service)
            $this->app->singleton(SmartTranslationService::class, function ($app) {
                  return new SmartTranslationService($app->make(TranslationService::class));
            });
      }

      /**
       * Bootstrap any package services.
       */
      public function boot(): void
      {
            if ($this->app->runningInConsole()) {
                  // 1. Publish the config file
                  // Users can run: php artisan vendor:publish --tag=lara-glot-config
                  $this->publishes([
                        __DIR__ . '/../config/lara-glot.php' => config_path('lara-glot.php'),
                  ], 'lara-glot-config');

                  // 2. Register the artisan command

                  $this->commands([
                        DispatchTranslations::class,
                  ]);
            }
      }
}