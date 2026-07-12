<?php

use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Tonydev\LaraGlot\Jobs\TranslateFilesJob;
use Tonydev\LaraGlot\Services\TranslationService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
      // Isolate lang_path — without this the tests share the testbench
      // skeleton's lang dir and pick up whatever files are lying there.
      app()->useLangPath(sys_get_temp_dir() . '/laraglot-files-cmd-' . uniqid());
});

it('dispatches jobs for all files and locales', function () {
      Queue::fake();

      // Create a minimal source lang file so the command finds something to process.
      $langDir = lang_path('en');
      @mkdir($langDir, 0755, true);
      file_put_contents("{$langDir}/messages.php", "<?php\nreturn ['hello' => 'Hello'];");

      $this->artisan('laraglot:files', ['--locale' => 'fr'])
            ->assertExitCode(0);

      Queue::assertPushed(TranslateFilesJob::class, function ($job) {
            return $job->fileName === 'messages' && $job->locale === 'fr';
      });

      // Clean up.
      @unlink("{$langDir}/messages.php");
});

it('runs synchronously with --sync flag', function () {
      // dispatch_sync() executes inline — it bypasses the queue worker entirely.
      // Queue::fake() would intercept it and cause a false failure, so we
      // run without a fake and swap in a driver that actually translates:
      // a driver that falls back to source now correctly fails the command.
      TranslationService::extend('fake', fn() => new class implements TranslationDriverInterface {
            public function translate(string $text, string $target, string $source = 'en'): string
            {
                  return "[{$target}] {$text}";
            }

            public function translateBatch(array $texts, string $target, string $source = 'en'): array
            {
                  return array_map(fn($t) => is_string($t) ? "[{$target}] {$t}" : $t, $texts);
            }
      });
      config(['lara-glot.translator' => 'fake']);

      $langDir = lang_path('en');
      @mkdir($langDir, 0755, true);
      file_put_contents("{$langDir}/messages.php", "<?php\nreturn ['hello' => 'Hello'];");

      $this->artisan('laraglot:files', [
            '--locale' => 'fr',
            '--sync' => true,
      ])->assertExitCode(0);

      $written = require lang_path('fr/messages.php');

      expect($written['hello'])->toBe('[fr] Hello');

      @unlink("{$langDir}/messages.php");
      @unlink(lang_path('fr/messages.php'));
});

it('exits non-zero and reports the failure when --sync translation fully falls back', function () {
      // A driver that returns everything unchanged is the signature of the
      // silent-fallback bug — the command must fail loudly, not print Done.
      TranslationService::extend('broken', fn() => new class implements TranslationDriverInterface {
            public function translate(string $text, string $target, string $source = 'en'): string
            {
                  return $text;
            }

            public function translateBatch(array $texts, string $target, string $source = 'en'): array
            {
                  return $texts;
            }
      });
      config(['lara-glot.translator' => 'broken']);

      $langDir = lang_path('en');
      @mkdir($langDir, 0755, true);
      file_put_contents("{$langDir}/messages.php", "<?php\nreturn ['hello' => 'Hello'];");

      $this->artisan('laraglot:files', [
            '--locale' => 'fr',
            '--sync' => true,
      ])->assertExitCode(1);

      // The untranslated file must NOT have been written.
      expect(file_exists(lang_path('fr/messages.php')))->toBeFalse();

      @unlink("{$langDir}/messages.php");
});

it('exits with failure when no languages are configured', function () {
      config(['lara-glot.languages' => []]);

      $this->artisan('laraglot:files')->assertExitCode(1);
});

it('skips existing files without --force', function () {
      Queue::fake();

      $langDir = lang_path('en');
      $targetDir = lang_path('fr');
      @mkdir($langDir, 0755, true);
      @mkdir($targetDir, 0755, true);
      file_put_contents("{$langDir}/messages.php", "<?php\nreturn ['hello' => 'Hello'];");
      file_put_contents("{$targetDir}/messages.php", "<?php\nreturn ['hello' => 'Bonjour'];");

      $this->artisan('laraglot:files', ['--locale' => 'fr'])
            ->assertExitCode(0);

      Queue::assertPushed(TranslateFilesJob::class, function ($job) {
            // Job dispatched but force=false — the job itself will skip.
            return $job->force === false;
      });

      @unlink("{$langDir}/messages.php");
      @unlink("{$targetDir}/messages.php");
});
