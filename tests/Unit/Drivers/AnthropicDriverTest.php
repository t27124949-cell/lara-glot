<?php

use Illuminate\Support\Facades\Http;
use Tonydev\LaraGlot\Drivers\AnthropicDriver;

/**
 * Builds a fake Messages API response whose text block contains the given
 * JSON-encoded array of translations.
 */
function anthropicResponse(array $translations): array
{
      return [
            'content' => [
                  [
                        'type' => 'text',
                        'text' => json_encode($translations, JSON_UNESCAPED_UNICODE),
                  ],
            ],
            'stop_reason' => 'end_turn',
      ];
}

beforeEach(function () {
      config()->set('concurrency.default', 'sync');
      config()->set('lara-glot.drivers.anthropic.api_key', 'test-key');
      config()->set('lara-glot.drivers.anthropic.max_retries', 1);
      config()->set('lara-glot.drivers.anthropic.retry_delay_ms', 1);
});

it('translates a batch through the messages endpoint', function () {
      Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(anthropicResponse(['Bonjour', 'Monde'])),
      ]);

      $driver = new AnthropicDriver();
      $result = $driver->translateBatch(['a' => 'Hello', 'b' => 'World'], 'fr');

      expect($result)->toBe(['a' => 'Bonjour', 'b' => 'Monde']);

      Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'test-key')
                  && $request->hasHeader('anthropic-version', '2023-06-01')
                  && $request['model'] === 'claude-haiku-4-5'
                  && isset($request['max_tokens'], $request['system']);
      });
});

it('sends identical strings to the API only once', function () {
      Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(anthropicResponse(['Bonjour'])),
      ]);

      $driver = new AnthropicDriver();
      $result = $driver->translateBatch([
            'title' => 'Hello',
            'heading' => 'Hello',
            'label' => 'Hello',
      ], 'fr');

      expect($result)->toEqual([
            'title' => 'Bonjour',
            'heading' => 'Bonjour',
            'label' => 'Bonjour',
      ]);

      Http::assertSentCount(1);

      // The single request must carry exactly one string.
      Http::assertSent(function ($request) {
            return count(json_decode($request['messages'][0]['content'], true)) === 1;
      });
});

it('falls back to original strings when the API fails permanently', function () {
      Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['error' => 'overloaded'], 529),
      ]);

      $driver = new AnthropicDriver();
      $result = $driver->translateBatch(['a' => 'Hello'], 'fr');

      expect($result)->toBe(['a' => 'Hello']);
});

it('keeps laravel placeholders intact end to end', function () {
      Http::fake(function ($request) {
            // Echo the protected strings back — a "translation" that keeps tokens.
            $input = json_decode($request['messages'][0]['content'], true);

            return Http::response(anthropicResponse($input));
      });

      $driver = new AnthropicDriver();
      $result = $driver->translateBatch(['a' => 'Hello :name, visit https://example.com'], 'fr');

      expect($result['a'])->toContain(':name')
            ->and($result['a'])->toContain('https://example.com')
            ->and($result['a'])->not->toContain('__VAR')
            ->and($result['a'])->not->toContain('__URL');
});

it('never sends glossary protected terms to the API', function () {
      config()->set('lara-glot.glossary.protected_terms', ['LaraGlot']);

      Http::fake(function ($request) {
            $input = json_decode($request['messages'][0]['content'], true);

            expect($input[0])->not->toContain('LaraGlot');

            return Http::response(anthropicResponse($input));
      });

      $driver = new AnthropicDriver();
      $result = $driver->translateBatch(['a' => 'Try LaraGlot today'], 'fr');

      expect($result['a'])->toContain('LaraGlot');
});

it('injects forced glossary terms into the system prompt', function () {
      config()->set('lara-glot.glossary.terms', [
            'checkout' => ['de' => 'Kasse', 'fr' => 'paiement'],
      ]);

      Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(anthropicResponse(['Zur Kasse'])),
      ]);

      $driver = new AnthropicDriver();
      $driver->translateBatch(['a' => 'Go to checkout'], 'de');

      Http::assertSent(function ($request) {
            return str_contains($request['system'], 'Kasse')
                  && !str_contains($request['system'], 'paiement');
      });
});

it('invalidates cached translations when the glossary changes', function () {
      Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(anthropicResponse(['Bonjour'])),
      ]);

      (new AnthropicDriver())->translateBatch(['a' => 'Hello'], 'fr');
      (new AnthropicDriver())->translateBatch(['a' => 'Hello'], 'fr');

      // Second call is a cache hit — still one request.
      Http::assertSentCount(1);

      config()->set('lara-glot.glossary.protected_terms', ['NewBrand']);
      (new AnthropicDriver())->translateBatch(['a' => 'Hello'], 'fr');

      // Different glossary → different cache key → one new request.
      Http::assertSentCount(2);
});

it('applies the review pass when enabled and caches the reviewed result', function () {
      config()->set('lara-glot.review.enabled', true);

      $calls = 0;

      Http::fake(function () use (&$calls) {
            $calls++;

            // First call: stiff draft. Second call (review): improved.
            return Http::response(anthropicResponse(
                  $calls === 1 ? ['Bonjour stiff'] : ['Bonjour naturel']
            ));
      });

      $driver = new AnthropicDriver();
      $result = $driver->translateBatch(['a' => 'Hello'], 'fr');

      expect($result)->toBe(['a' => 'Bonjour naturel'])
            ->and($calls)->toBe(2);

      // Cached result is the post-review one — no further API calls.
      $again = (new AnthropicDriver())->translateBatch(['a' => 'Hello'], 'fr');

      expect($again)->toBe(['a' => 'Bonjour naturel'])
            ->and($calls)->toBe(2);
});

it('keeps the first draft when the review pass fails', function () {
      config()->set('lara-glot.review.enabled', true);

      $calls = 0;

      Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1
                  ? Http::response(anthropicResponse(['Bonjour']))
                  : Http::response(['error' => 'overloaded'], 529);
      });

      $driver = new AnthropicDriver();
      $result = $driver->translateBatch(['a' => 'Hello'], 'fr');

      expect($result)->toBe(['a' => 'Bonjour']);
});

it('uses a custom prompt override when configured', function () {
      config()->set('lara-glot.prompt', 'Translate from {source} to {target}. JSON array only.');

      Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(anthropicResponse(['Bonjour'])),
      ]);

      (new AnthropicDriver())->translateBatch(['a' => 'Hello'], 'fr');

      Http::assertSent(function ($request) {
            return $request['system'] === 'Translate from en to fr. JSON array only.';
      });
});
