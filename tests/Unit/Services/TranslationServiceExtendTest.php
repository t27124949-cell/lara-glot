<?php

use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Tonydev\LaraGlot\Services\TranslationService;

it('resolves a custom driver registered via extend()', function () {
      $custom = new class implements TranslationDriverInterface {
            public function translate(string $text, string $target, string $source = 'en'): string
            {
                  return "custom:{$text}";
            }

            public function translateBatch(array $texts, string $target, string $source = 'en'): array
            {
                  return array_map(fn($t) => "custom:{$t}", $texts);
            }
      };

      TranslationService::extend('my-engine', fn() => $custom);
      config()->set('lara-glot.translator', 'my-engine');

      $service = new TranslationService();

      expect($service->translate('Hello', 'fr'))->toBe('custom:Hello');
});
