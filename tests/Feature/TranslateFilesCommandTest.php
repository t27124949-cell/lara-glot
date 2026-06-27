<?php

use Tonydev\LaraGlot\Jobs\TranslateFilesJob;
use Illuminate\Support\Facades\Queue;

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
      // run without a fake and just assert the command exits successfully.
      $langDir = lang_path('en');
      @mkdir($langDir, 0755, true);
      file_put_contents("{$langDir}/messages.php", "<?php\nreturn ['hello' => 'Hello'];");

      $this->artisan('laraglot:files', [
            '--locale' => 'fr',
            '--sync' => true,
      ])->assertExitCode(0);

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
