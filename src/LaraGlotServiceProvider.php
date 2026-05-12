<?php

namespace Tonydev\LaraGlot;

use Illuminate\Support\ServiceProvider;
use Tonydev\LaraGlot\Commands\DispatchTranslations;
use Tonydev\LaraGlot\Commands\TranslateFilesCommand;
use Tonydev\LaraGlot\Services\FileTranslationService;
use Tonydev\LaraGlot\Services\SmartTranslationService;
use Tonydev\LaraGlot\Services\TranslationService;

class LaraGlotServiceProvider extends ServiceProvider
{
      /**
       * Register services in the container.
       */
      public function register(): void
      {
            $this->mergeConfigFrom(
                  __DIR__ . '/../config/lara-glot.php',
                  'lara-glot'
            );

            $this->app->singleton(TranslationService::class, fn() => new TranslationService());

            $this->app->singleton(FileTranslationService::class, function ($app) {
                  return new FileTranslationService(
                        $app->make(TranslationService::class)
                  );
            });

            $this->app->singleton(SmartTranslationService::class, function ($app) {
                  return new SmartTranslationService(
                        $app->make(TranslationService::class)
                  );
            });
      }

      /**
       * Bootstrap any package services.
       */
      public function boot(): void
      {
            if ($this->app->runningInConsole()) {
                  $this->publishes([
                        __DIR__ . '/../config/lara-glot.php' => config_path('lara-glot.php'),
                  ], 'lara-glot-config');

                  $this->commands([
                        DispatchTranslations::class,
                        TranslateFilesCommand::class,
                  ]);
            }

            $this->loadViewsFrom(
                  __DIR__ . '/../resources/views',
                  'lara-glot'
            );
      }
}