<?php
namespace Tonydev\LaraGlot;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Tonydev\LaraGlot\Filament\Pages\LaraGlotManager;

class LaraGlotPlugin implements Plugin
{
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
            // optional boot logic
      }
}