<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Tonydev\LaraGlot\Drivers\AbstractTranslationDriver;

/**
 * Minimal concrete driver exposing the protected helpers under test.
 */
function makeBareDriver(): AbstractTranslationDriver
{
      return new class extends AbstractTranslationDriver {
            protected function driverName(): string
            {
                  return 'test';
            }

            public function translateBatch(array $texts, string $target, string $source = 'en'): array
            {
                  return $texts;
            }

            public function runBatches(array $tasks, int $batchSize): array
            {
                  return $this->runConcurrentBatches($tasks, $batchSize);
            }

            public function readCache(string $key): ?string
            {
                  return $this->getFromCache($key);
            }
      };
}

// ── Multibyte transport (bug: process driver corrupted UTF-8 results) ─────────

it('round-trips multibyte results through the concurrency boundary intact', function () {
      config()->set('concurrency.default', 'sync');

      $results = makeBareDriver()->runBatches([
            'ar' => fn(): string => 'يلغي',
            'zh' => fn(): string => '中文翻译',
            'chunk' => fn(): array => ['h1' => 'हिन्दी अनुवाद'],
      ], 2);

      expect($results)->toBe([
            'ar' => 'يلغي',
            'zh' => '中文翻译',
            'chunk' => ['h1' => 'हिन्दी अनुवाद'],
      ]);
});

it('only sends ascii-safe payloads across the concurrency boundary', function () {
      $transported = [];

      // Stand-in for any concurrency driver: run each task and capture exactly
      // what would cross the process boundary.
      Concurrency::shouldReceive('run')->andReturnUsing(
            function (array $tasks) use (&$transported): array {
                  return array_map(function (callable $task) use (&$transported) {
                        $value = $task();
                        $transported[] = $value;

                        return $value;
                  }, $tasks);
            }
      );

      makeBareDriver()->runBatches([
            'ar' => fn(): string => 'ترجمة عربية طويلة نسبياً لاختبار الترميز',
            'nested' => fn(): array => ['a' => '翻訳', 'b' => 'çeviri'],
      ], 5);

      // The process concurrency driver mangles raw multibyte output; base64 is
      // the invariant that keeps results safe on every driver.
      foreach ($transported as $value) {
            expect($value)->toBeString()
                  ->and(preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $value))->toBe(1);
      }

      expect($transported)->toHaveCount(2);
});

// ── Corrupt cache entries (bug: poisoned key failed until cache:clear) ────────

it('treats an unreadable cache entry as a miss and evicts it', function () {
      Cache::shouldReceive('get')
            ->once()
            ->andThrow(new \ErrorException('unserialize(): Error at offset 955 of 1143 bytes'));
      Cache::shouldReceive('forget')->once();

      expect(makeBareDriver()->readCache('laraglot:test:en:ar:deadbeef'))->toBeNull();
});

it('still returns a miss when evicting the corrupt entry also fails', function () {
      Cache::shouldReceive('get')->once()->andThrow(new \RuntimeException('read failed'));
      Cache::shouldReceive('forget')->once()->andThrow(new \RuntimeException('delete failed'));

      expect(makeBareDriver()->readCache('laraglot:test:en:ar:deadbeef'))->toBeNull();
});
