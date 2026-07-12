<?php

use Tonydev\LaraGlot\Drivers\GoogleDriver;

/**
 * Exposes the protected long-string and token-verification helpers.
 * Nothing here touches the network or the stichoza client.
 */
function makeGoogleDriver(): GoogleDriver
{
      return new class extends GoogleDriver {
            public function split(string $text, int $maxLength): array
            {
                  return $this->splitLongText($text, $maxLength);
            }

            public function tokensSurvived(string $translated, array $placeholders): bool
            {
                  return $this->placeholdersSurvived($translated, $placeholders);
            }
      };
}

// ── Long-string splitting ─────────────────────────────────────────────────────

it('splits long text on sentence boundaries within the length cap', function () {
      $text = 'First sentence here. Second sentence follows! Third one asks? Fourth closes.';

      $segments = makeGoogleDriver()->split($text, 30);

      expect(count($segments))->toBeGreaterThan(1)
            ->and(implode(' ', $segments))->toBe($text);

      foreach ($segments as $segment) {
            expect(mb_strlen($segment))->toBeLessThanOrEqual(30);
      }
});

it('packs short sentences together to minimise requests', function () {
      $text = 'One. Two. Three. Four.';

      // All four fit in a single 100-char segment.
      expect(makeGoogleDriver()->split($text, 100))->toBe([$text]);
});

it('falls back to word boundaries for a single overlong sentence', function () {
      $text = str_repeat('word ', 50) . 'end';

      $segments = makeGoogleDriver()->split($text, 60);

      foreach ($segments as $segment) {
            expect(mb_strlen($segment))->toBeLessThanOrEqual(60);
      }

      expect(implode(' ', $segments))->toBe($text);
});

it('never cuts a placeholder token in half when splitting', function () {
      $text = 'Intro sentence explaining things. Visit __URL_0__ for details and __BRACE_1__ says hi. Closing sentence here.';

      $segments = makeGoogleDriver()->split($text, 45);
      $joined = implode(' ', $segments);

      expect($joined)->toContain('__URL_0__')
            ->and($joined)->toContain('__BRACE_1__');
});

// ── Placeholder round-trip verification ───────────────────────────────────────

it('accepts a response where every token survived', function () {
      $survived = makeGoogleDriver()->tokensSurvived(
            'مرحبا __BRACE_0__ زر __URL_1__',
            ['__BRACE_0__' => ':name', '__URL_1__' => 'https://example.com']
      );

      expect($survived)->toBeTrue();
});

it('rejects a response where the engine mangled a token', function () {
      // Google produced "الطريق: الاتصال"-style damage: token translated/re-cased.
      $survived = makeGoogleDriver()->tokensSurvived(
            'مرحبا __brace_0__',
            ['__BRACE_0__' => ':name']
      );

      expect($survived)->toBeFalse();
});
