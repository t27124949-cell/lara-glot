<?php

namespace Tonydev\LaraGlot\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tonydev\LaraGlot\LaraGlotServiceProvider;

class TestCase extends Orchestra
{
      protected function getPackageProviders($app): array
      {
            return [
                  LaraGlotServiceProvider::class,
            ];
      }

      protected function defineEnvironment($app): void
      {
            $app['config']->set('lara-glot.translator', 'google');
            $app['config']->set('lara-glot.source_locale', 'en');
            $app['config']->set('lara-glot.cache_expiry', 60);
            $app['config']->set('lara-glot.queue', 'default');
            $app['config']->set('lara-glot.languages', [
                  'en' => ['name' => 'English', 'flag' => '🇬🇧'],
                  'fr' => ['name' => 'Français', 'flag' => '🇫🇷'],
                  'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪'],
            ]);
            $app['config']->set('lara-glot.ignored_keys', [
                  'id',
                  'slug',
                  'url',
                  'uuid',
            ]);
            $app['config']->set('lara-glot.exclude_files', [
                  'auth',
                  'pagination',
                  'passwords',
                  'validation',
            ]);
            $app['config']->set('cache.default', 'array');
            $app['config']->set('queue.default', 'sync');
      }
}
