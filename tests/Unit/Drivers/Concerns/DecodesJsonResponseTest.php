<?php

use Tonydev\LaraGlot\Drivers\Concerns\DecodesJsonResponse;

$decoder = new class {
      use DecodesJsonResponse;

      public function decode(string $raw): ?array
      {
            return $this->decodeJsonResponse($raw, 'Test');
      }
};

it('decodes a clean json array', function () use ($decoder) {
      $result = $decoder->decode('["Hello","World"]');

      expect($result)->toBe(['Hello', 'World']);
});

it('strips markdown code fences', function () use ($decoder) {
      $result = $decoder->decode("```json\n[\"Hello\",\"World\"]\n```");

      expect($result)->toBe(['Hello', 'World']);
});

it('strips utf-8 bom', function () use ($decoder) {
      $result = $decoder->decode("\xEF\xBB\xBF[\"Hello\"]");

      expect($result)->toBe(['Hello']);
});

it('unwraps single-key envelope objects', function () use ($decoder) {
      $result = $decoder->decode('{"translations": ["Hello","World"]}');

      expect($result)->toBe(['Hello', 'World']);
});

it('returns null for invalid json', function () use ($decoder) {
      $result = $decoder->decode('this is not json at all');

      expect($result)->toBeNull();
});

it('returns null for non-array decoded value', function () use ($decoder) {
      $result = $decoder->decode('"just a string"');

      expect($result)->toBeNull();
});

it('handles crlf line endings', function () use ($decoder) {
      $result = $decoder->decode("[\"Hello\",\r\n\"World\"]");

      expect($result)->toBe(['Hello', 'World']);
});
