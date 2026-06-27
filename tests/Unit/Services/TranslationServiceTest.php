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

it('falls back to originals when batch driver throws', function () {
      $this->driver
            ->shouldReceive('translateBatch')
            ->andThrow(new \RuntimeException('API error'));

      $result = $this->service->translateBatch(['Hello', 'World'], 'fr');

      expect($result)->toBe([0 => 'Hello', 1 => 'World']);
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
