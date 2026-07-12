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

// ── Protected value patterns ──────────────────────────────────────────────────

it('passes route: and URL values through untranslated', function () {
      // Only the human-readable label may reach the driver.
      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->with(['Contact us'], 'fr', 'en')
            ->andReturn(['Contactez-nous']);

      $result = $this->service->translateBatch([
            'contact.label' => 'Contact us',
            'contact.link' => 'route:contact',
            'contact.site' => 'https://example.com/help',
            'contact.mail' => 'mailto:hi@example.com',
      ], 'fr');

      expect($result['contact.label'])->toBe('Contactez-nous')
            ->and($result['contact.link'])->toBe('route:contact')
            ->and($result['contact.site'])->toBe('https://example.com/help')
            ->and($result['contact.mail'])->toBe('mailto:hi@example.com');
});

it('still translates prose that merely contains a URL', function () {
      // The pattern anchors to whole values — text with an embedded URL must
      // go through (the driver protects the URL itself via placeholders).
      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->with(['Visit https://example.com today'], 'fr', 'en')
            ->andReturn(['Visitez https://example.com aujourd\'hui']);

      $result = $this->service->translateBatch([
            'cta' => 'Visit https://example.com today',
      ], 'fr');

      expect($result['cta'])->toBe('Visitez https://example.com aujourd\'hui');
});

// ── translateFile fallback detection ──────────────────────────────────────────

function setUpLangFixture(array $source): void
{
      $langDir = sys_get_temp_dir() . '/laraglot-test-' . uniqid();
      mkdir($langDir . '/en', 0755, true);
      file_put_contents(
            $langDir . '/en/fixture.php',
            "<?php\n\nreturn " . var_export($source, true) . ";\n"
      );

      app()->useLangPath($langDir);
}

it('returns fallback stats and writes the file on a successful run', function () {
      setUpLangFixture(['hello' => 'Hello', 'world' => 'World', 'brand' => 'Acme']);

      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->andReturn(['Bonjour', 'Monde', 'Acme']); // brand name legitimately identical

      $stats = $this->service->translateFile('fixture', 'fr');

      expect($stats)->toBe(['total' => 3, 'identical' => 1, 'translated' => 2, 'ratio' => 0.3333])
            ->and(file_exists(lang_path('fr/fixture.php')))->toBeTrue();

      $written = require lang_path('fr/fixture.php');

      expect($written['hello'])->toBe('Bonjour');
});

it('throws and does not write the file when every string falls back to source', function () {
      setUpLangFixture(['hello' => 'Hello', 'world' => 'World']);

      // A driver failure surfaces as output identical to input.
      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->andReturn(['Hello', 'World']);

      expect(fn() => $this->service->translateFile('fixture', 'ar'))
            ->toThrow(\RuntimeException::class, 'identical to the source');

      expect(file_exists(lang_path('ar/fixture.php')))->toBeFalse();
});

it('writes a fully-identical file when fail_on_full_fallback is disabled', function () {
      config()->set('lara-glot.fallback.fail_on_full_fallback', false);

      setUpLangFixture(['brand' => 'Acme']);

      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->andReturn(['Acme']);

      $stats = $this->service->translateFile('fixture', 'fr');

      expect($stats['identical'])->toBe(1)
            ->and(file_exists(lang_path('fr/fixture.php')))->toBeTrue();
});
