<?php

use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Tonydev\LaraGlot\Services\TranslationService;

function writeAuditLangFile(string $locale, string $fileName, array $data): void
{
      $dir = lang_path($locale);
      @mkdir($dir, 0755, true);
      file_put_contents(
            "{$dir}/{$fileName}.php",
            "<?php\n\nreturn " . var_export($data, true) . ";\n"
      );
}

beforeEach(function () {
      app()->useLangPath(sys_get_temp_dir() . '/laraglot-audit-cmd-' . uniqid());
});

it('passes when translations differ from the source', function () {
      writeAuditLangFile('en', 'app', ['hello' => 'Hello']);
      writeAuditLangFile('fr', 'app', ['hello' => 'Bonjour']);
      writeAuditLangFile('de', 'app', ['hello' => 'Hallo']);

      $this->artisan('laraglot:audit', ['--files-only' => true])
            ->assertExitCode(0);
});

it('exits non-zero when a file is fully identical to the source', function () {
      writeAuditLangFile('en', 'app', ['hello' => 'Hello', 'bye' => 'Goodbye']);
      writeAuditLangFile('fr', 'app', ['hello' => 'Hello', 'bye' => 'Goodbye']); // silent fallback

      $this->artisan('laraglot:audit', ['--files-only' => true, '--locale' => ['fr']])
            ->assertExitCode(1);
});

it('repairs flagged files and then passes', function () {
      TranslationService::extend('fake-audit', fn() => new class implements TranslationDriverInterface {
            public function translate(string $text, string $target, string $source = 'en'): string
            {
                  return "[{$target}] {$text}";
            }

            public function translateBatch(array $texts, string $target, string $source = 'en'): array
            {
                  return array_map(fn($t) => is_string($t) ? "[{$target}] {$t}" : $t, $texts);
            }
      });
      config(['lara-glot.translator' => 'fake-audit']);

      writeAuditLangFile('en', 'app', ['hello' => 'Hello', 'bye' => 'Goodbye']);
      writeAuditLangFile('fr', 'app', ['hello' => 'Hello', 'bye' => 'Goodbye']);

      $this->artisan('laraglot:audit', [
            '--files-only' => true,
            '--locale' => ['fr'],
            '--repair' => true,
      ])->assertExitCode(0);

      $repaired = require lang_path('fr/app.php');

      expect($repaired)->toBe(['hello' => '[fr] Hello', 'bye' => '[fr] Goodbye']);
});

it('respects a custom threshold', function () {
      writeAuditLangFile('en', 'app', ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma', 'd' => 'Delta']);
      // 1 of 4 identical = 25% — under the default 85%, over a 20% threshold.
      writeAuditLangFile('fr', 'app', ['a' => 'Alpha', 'b' => 'Bêta', 'c' => 'Gamma-fr', 'd' => 'Delta-fr']);

      $this->artisan('laraglot:audit', ['--files-only' => true, '--locale' => ['fr']])
            ->assertExitCode(0);

      $this->artisan('laraglot:audit', [
            '--files-only' => true,
            '--locale' => ['fr'],
            '--threshold' => '0.2',
      ])->assertExitCode(1);
});
