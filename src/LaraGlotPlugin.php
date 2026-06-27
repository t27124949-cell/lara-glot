<?php

namespace Tonydev\LaraGlot;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Tonydev\LaraGlot\Filament\Pages\LaraGlotManager;

class LaraGlotPlugin implements Plugin
{
      /**
       * Fluent factory — Filament convention for plugin registration.
       *
       * Usage in AppServiceProvider or PanelProvider:
       *   ->plugins([LaraGlotPlugin::make()])
       */
      public static function make(): static
      {
            return app(static::class);
      }

      public function getId(): string
      {
            return 'lara-glot';
      }

      public function register(Panel $panel): void
      {
            $panel->pages([
                  LaraGlotManager::class,
            ]);
      }

      public function boot(Panel $panel): void
      {
            // Reserved for future boot-time logic.
      }
}