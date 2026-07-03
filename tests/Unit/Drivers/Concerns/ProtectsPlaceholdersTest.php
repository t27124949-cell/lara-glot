<?php

use Tonydev\LaraGlot\Drivers\Concerns\ProtectsPlaceholders;

// Anonymous class that exposes the protected methods for testing
$driver = new class {
      use ProtectsPlaceholders;

      public function protect(string $text): array
      {
            return $this->protectPlaceholders($text);
      }

      public function restore(string $text, array $map): string
      {
            return $this->restorePlaceholders($text, $map);
      }
};

it('protects laravel :word placeholders', function () use ($driver) {
      [$protected, $map] = $driver->protect('Hello :name, you have :count messages.');

      expect($map)->toHaveCount(2);
      expect(array_values($map))->toContain(':name')->toContain(':count');
      expect($protected)->not->toContain(':name')->not->toContain(':count');
});

it('restores placeholders after translation', function () use ($driver) {
      [$protected, $map] = $driver->protect('Welcome :name!');
      $fakeTranslated = str_replace(array_keys($map), array_values($map), $protected);

      $restored = $driver->restore($fakeTranslated, $map);

      expect($restored)->toBe('Welcome :name!');
});

it('protects absolute URLs', function () use ($driver) {
      [$protected, $map] = $driver->protect('Visit https://example.com/path for details.');

      expect($protected)->not->toContain('https://example.com');
      expect($map)->toHaveCount(1);
      expect(array_values($map)[0])->toBe('https://example.com/path');
});

it('protects html no-translate spans', function () use ($driver) {
      $text = 'Hello <span translate="no">LaraGlot</span> world.';
      [$protected, $map] = $driver->protect($text);

      expect($protected)->not->toContain('<span');
      expect($map)->toHaveCount(1);
});

it('uses a unique counter across all token types', function () use ($driver) {
      $text = 'Hi :name, see https://example.com and <span translate="no">tag</span>.';
      [$protected, $map] = $driver->protect($text);

      // All three token types must be captured.
      expect($map)->toHaveCount(3);
      expect(array_values($map))->toContain(':name')
            ->toContain('https://example.com')
            ->toContain('<span translate="no">tag</span>');

      // Protected string must contain none of the originals.
      expect($protected)->not->toContain(':name')
            ->not->toContain('https://example.com')
            ->not->toContain('<span');
});

it('returns original text unchanged when no placeholders present', function () use ($driver) {
      [$protected, $map] = $driver->protect('Simple string with no tokens.');

      expect($protected)->toBe('Simple string with no tokens.');
      expect($map)->toBeEmpty();
});

it('handles empty string gracefully', function () use ($driver) {
      [$protected, $map] = $driver->protect('');

      expect($protected)->toBe('');
      expect($map)->toBeEmpty();
});

it('protects glossary terms case-sensitively as whole words', function () use ($driver) {
      config()->set('lara-glot.glossary.protected_terms', ['LaraGlot']);

      [$protected, $map] = $driver->protect('Try LaraGlot — but laraglots or MyLaraGlot stay untouched.');

      expect($protected)->toContain('__TERM_0__')
            ->and($protected)->toContain('laraglots')
            ->and($protected)->toContain('MyLaraGlot')
            ->and($map['__TERM_0__'])->toBe('LaraGlot');

      expect($driver->restore($protected, $map))
            ->toBe('Try LaraGlot — but laraglots or MyLaraGlot stay untouched.');
});

it('leaves glossary terms inside urls to the url rule', function () use ($driver) {
      config()->set('lara-glot.glossary.protected_terms', ['LaraGlot']);

      [$protected, $map] = $driver->protect('Docs: https://laraglot.dev/LaraGlot/guide');

      // The whole URL is one token; no __TERM_ token was created inside it.
      expect(array_keys($map))->toHaveCount(1)
            ->and($driver->restore($protected, $map))->toBe('Docs: https://laraglot.dev/LaraGlot/guide');
});
