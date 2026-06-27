<?php

use Tonydev\LaraGlot\Services\FileTranslationService;
use Tonydev\LaraGlot\Services\TranslationService;

beforeEach(function () {
      // Mock TranslationService so no real API calls are made.
      $this->translator = Mockery::mock(TranslationService::class);
      $this->service = new FileTranslationService($this->translator);
});

afterEach(fn() => Mockery::close());

it('passes translatable keys to the driver', function () {
      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->with(['Hello', 'World'], 'fr', 'en')
            ->andReturn(['Bonjour', 'Monde']);

      $result = $this->service->translateBatch([
            'greeting' => 'Hello',
            'farewell' => 'World',
      ], 'fr');

      expect($result)->toBe([
            'greeting' => 'Bonjour',
            'farewell' => 'Monde',
      ]);
});

it('skips ignored keys and passes them through unchanged', function () {
      // Only 'title' should be sent — 'slug' and 'url' are in ignored_keys config.
      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->with(['Welcome'], 'fr', 'en')
            ->andReturn(['Bienvenue']);

      $result = $this->service->translateBatch([
            'title' => 'Welcome',
            'slug' => 'welcome-page',
            'url' => '/welcome',
      ], 'fr');

      expect($result['title'])->toBe('Bienvenue')
            ->and($result['slug'])->toBe('welcome-page')
            ->and($result['url'])->toBe('/welcome');
});

it('skips ignored keys matched by last dot-notation segment', function () {
      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->with(['Some text'], 'fr', 'en')
            ->andReturn(['Du texte']);

      $result = $this->service->translateBatch([
            'page.title' => 'Some text',
            'page.slug' => 'some-page',   // 'slug' is ignored
      ], 'fr');

      expect($result['page.title'])->toBe('Du texte')
            ->and($result['page.slug'])->toBe('some-page');
});

it('returns empty array for empty input', function () {
      $this->translator->shouldNotReceive('translateBatch');

      $result = $this->service->translateBatch([], 'fr');

      expect($result)->toBeEmpty();
});

it('throws when driver returns wrong count', function () {
      $this->translator
            ->shouldReceive('translateBatch')
            ->andReturn(['only one item']);

      expect(fn() => $this->service->translateBatch([
            'a' => 'Hello',
            'b' => 'World',
      ], 'fr'))->toThrow(\RuntimeException::class);
});

it('preserves original key order in output', function () {
      $this->translator
            ->shouldReceive('translateBatch')
            ->andReturn(['Bonjour', 'Monde', 'Au revoir']);

      $result = $this->service->translateBatch([
            'a' => 'Hello',
            'b' => 'World',
            'c' => 'Goodbye',
      ], 'fr');

      expect(array_keys($result))->toBe(['a', 'b', 'c']);
});
