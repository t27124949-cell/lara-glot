<?php

use Tonydev\LaraGlot\Services\TranslationService;
use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
      // Swap the real driver with a mock so no HTTP calls are made.
      $this->driver = Mockery::mock(TranslationDriverInterface::class);

      $this->service = new class ($this->driver) extends TranslationService {
            public function __construct(protected TranslationDriverInterface $mockDriver)
            {
                  // Skip parent constructor (which resolves from config).
                  $this->driver = $mockDriver;
            }
      };
});

afterEach(fn() => Mockery::close());

// ── translate() ───────────────────────────────────────────────────────────────

it('calls the driver on a cache miss', function () {
      $this->driver
            ->shouldReceive('translate')
            ->once()
            ->with('Hello', 'fr', 'en')
            ->andReturn('Bonjour');

      $result = $this->service->translate('Hello', 'fr');

      expect($result)->toBe('Bonjour');
});

it('serves from persistent cache on second call', function () {
      $this->driver
            ->shouldReceive('translate')
            ->once()
            ->andReturn('Bonjour');

      $this->service->translate('Hello', 'fr');
      $result = $this->service->translate('Hello', 'fr'); // second call — cache hit

      expect($result)->toBe('Bonjour');
});

it('serves from in-process local cache without hitting the driver twice', function () {
      // Driver should only be called once — second call hits the local cache.
      $this->driver
            ->shouldReceive('translate')
            ->once()
            ->andReturn('Bonjour');

      $first = $this->service->translate('Hello', 'fr');
      $second = $this->service->translate('Hello', 'fr');

      expect($first)->toBe('Bonjour')
            ->and($second)->toBe('Bonjour');
});

it('bypasses cache and calls driver when force=true', function () {
      $this->driver
            ->shouldReceive('translate')
            ->twice()
            ->andReturn('Bonjour');

      $this->service->translate('Hello', 'fr');
      $result = $this->service->translate('Hello', 'fr', 'en', force: true);

      expect($result)->toBe('Bonjour');
});

it('returns original text when driver throws', function () {
      $this->driver
            ->shouldReceive('translate')
            ->andThrow(new \RuntimeException('API error'));

      $result = $this->service->translate('Hello', 'fr');

      expect($result)->toBe('Hello');
});

it('returns original text for blank input', function () {
      $this->driver->shouldNotReceive('translate');

      expect($this->service->translate('', 'fr'))->toBe('');
      expect($this->service->translate('   ', 'fr'))->toBe('   ');
});

it('returns original text for pure html with no visible text', function () {
      $this->driver->shouldNotReceive('translate');

      $result = $this->service->translate('<br>', 'fr');

      expect($result)->toBe('<br>');
});

// ── translateBatch() ──────────────────────────────────────────────────────────

it('translates a batch of strings', function () {
      $this->driver
            ->shouldReceive('translateBatch')
            ->once()
            ->andReturn([0 => 'Bonjour', 1 => 'Monde']);

      $result = $this->service->translateBatch(['Hello', 'World'], 'fr');

      expect($result)->toBe([0 => 'Bonjour', 1 => 'Monde']);
});

it('skips non-string values in batch', function () {
      // Service passes keyed array to driver — keys are preserved, not reindexed.
      $this->driver
            ->shouldReceive('translateBatch')
            ->once()
            ->with(['text' => 'Hello'], 'fr', 'en')
            ->andReturn(['text' => 'Bonjour']);

      $result = $this->service->translateBatch([
            'text' => 'Hello',
            'count' => 42,
      ], 'fr');

      expect($result['text'])->toBe('Bonjour')
            ->and($result['count'])->toBe(42);
});

it('preserves original key order in batch output', function () {
      $this->driver
            ->shouldReceive('translateBatch')
            ->andReturn(['Bonjour', 'Monde', 'Au revoir']);

      $result = $this->service->translateBatch([
            'a' => 'Hello',
            'b' => 'World',
            'c' => 'Goodbye',
      ], 'fr');

      expect(array_keys($result))->toBe(['a', 'b', 'c']);
});

it('throws when the batch driver fails instead of silently falling back to source', function () {
      $this->driver
            ->shouldReceive('translateBatch')
            ->andThrow(new \RuntimeException('API error'));

      // Batch callers are queue jobs / console commands — a driver failure must
      // fail the job, not write source text that then masquerades as done.
      expect(fn() => $this->service->translateBatch(['Hello', 'World'], 'fr'))
            ->toThrow(\RuntimeException::class);
});

it('does not cache source text when the batch driver fails', function () {
      $this->driver
            ->shouldReceive('translateBatch')
            ->once()
            ->andThrow(new \RuntimeException('API error'));

      try {
            $this->service->translateBatch(['Hello'], 'fr');
      } catch (\RuntimeException) {
            // expected
      }

      // A fresh service with a working driver must reach the driver — if the
      // failed run had cached 'Hello' as the fr translation, this would be a
      // (poisoned) cache hit returning English.
      $workingDriver = Mockery::mock(TranslationDriverInterface::class);
      $workingDriver
            ->shouldReceive('translateBatch')
            ->once()
            ->andReturn([0 => 'Bonjour']);

      $service = new class ($workingDriver) extends TranslationService {
            public function __construct(protected TranslationDriverInterface $mockDriver)
            {
                  $this->driver = $mockDriver;
            }
      };

      expect($service->translateBatch(['Hello'], 'fr'))->toBe([0 => 'Bonjour']);
});

it('treats a corrupt cache entry as a miss during batch translation', function () {
      Cache::shouldReceive('get')
            ->once()
            ->andThrow(new \ErrorException('unserialize(): Error at offset 955 of 1143 bytes'));
      Cache::shouldReceive('forget')->once();
      Cache::shouldReceive('put');

      $this->driver
            ->shouldReceive('translateBatch')
            ->once()
            ->andReturn([0 => 'Bonjour']);

      expect($this->service->translateBatch(['Hello'], 'fr'))->toBe([0 => 'Bonjour']);
});

it('bypasses the driver-level cache when forcing a batch refresh', function () {
      // A driver with its own string-level cache holding a stale (poisoned)
      // value. force=true must reach the API, not the driver's cache.
      $driver = new class implements TranslationDriverInterface {
            public bool $cacheEnabled = true;

            public function setCacheEnabled(bool $enabled): static
            {
                  $this->cacheEnabled = $enabled;

                  return $this;
            }

            public function isCacheEnabled(): bool
            {
                  return $this->cacheEnabled;
            }

            public function translate(string $text, string $target, string $source = 'en'): string
            {
                  return $this->cacheEnabled ? $text /* stale cached English */ : 'Bonjour';
            }

            public function translateBatch(array $texts, string $target, string $source = 'en'): array
            {
                  return array_map(fn($t) => $this->translate($t, $target, $source), $texts);
            }
      };

      $service = new class ($driver) extends TranslationService {
            public function __construct(protected TranslationDriverInterface $mockDriver)
            {
                  $this->driver = $mockDriver;
            }
      };

      // Without force: driver cache wins (the pre-fix behavior).
      expect($service->translateBatch(['Hello'], 'fr'))->toBe([0 => 'Hello']);

      // With force: punches through BOTH cache layers…
      expect($service->translateBatch(['Hello'], 'fr', 'en', force: true))->toBe([0 => 'Bonjour']);

      // …and restores the driver's previous cache setting afterwards.
      expect($driver->cacheEnabled)->toBeTrue();
});

// ── Cache key includes source locale ─────────────────────────────────────────

it('produces different cache keys for different source locales', function () {
      $this->driver
            ->shouldReceive('translate')
            ->twice()
            ->andReturn('result');

      // Translate the same text from two different source locales.
      $this->service->translate('Hello', 'de', 'en');
      $this->service->translate('Hello', 'de', 'fr');

      // Both calls should reach the driver — different cache keys.
      $this->driver->shouldHaveReceived('translate')->twice();
});
