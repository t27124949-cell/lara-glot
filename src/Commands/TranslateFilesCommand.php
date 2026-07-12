<?php

namespace Tonydev\LaraGlot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tonydev\LaraGlot\Jobs\TranslateFilesJob;
use Tonydev\LaraGlot\Services\FileTranslationService;

class TranslateFilesCommand extends Command
{
      /**
       * The name and signature of the console command.
       *
       * Register this in your ServiceProvider's $commands array so Artisan
       * can discover it:
       *
       *   protected $commands = [
       *       TranslateFilesCommand::class,
       *       DispatchTranslations::class,
       *   ];
       */
      protected $signature = 'laraglot:files
                              {file?     : Specific file name to translate (e.g. auth or auth.php)}
                              {--locale= : Only translate for this locale (e.g. fr)}
                              {--force   : Overwrite already-existing translation files}
                              {--sync    : Run jobs synchronously instead of queuing them}';

      protected $description = 'Dispatch background jobs to translate PHP language files to all configured locales';

      public function handle(): int
      {
            // ── Resolve target locales ────────────────────────────────────────────
            $sourceLocale = config('lara-glot.source_locale', 'en');
            $languages = config('lara-glot.languages', []);

            if (empty($languages)) {
                  $this->error('❌ No languages defined in config/lara-glot.php');
                  return self::FAILURE;
            }

            $targetLocales = collect($languages)
                  ->keys()
                  ->reject(fn($locale) => $locale === $sourceLocale)
                  ->when(
                        $this->option('locale'),
                        fn($col) => $col->filter(fn($l) => $l === $this->option('locale'))
                  )
                  ->values()
                  ->all();

            if (empty($targetLocales)) {
                  $this->error('❌ No valid target locales found. Check --locale value or lara-glot config.');
                  return self::FAILURE;
            }

            // ── Resolve target files ──────────────────────────────────────────────
            $files = $this->resolveFiles();

            if (empty($files)) {
                  $this->warn('🤔 No language files found to process.');
                  return self::SUCCESS;
            }

            $force = (bool) $this->option('force');
            $sync = (bool) $this->option('sync');
            $queue = config('lara-glot.queue', 'translations');
            $jobCount = 0;
            $failures = 0;

            $this->info(sprintf(
                  '🔍 Dispatching jobs for %d file(s) × %d locale(s) [queue: %s]',
                  count($files),
                  count($targetLocales),
                  $sync ? 'sync' : $queue
            ));

            foreach ($targetLocales as $locale) {
                  $this->line("\n🚀 Locale: [{$locale}]");

                  foreach ($files as $fileName) {
                        $this->line("  📄 {$fileName}.php → queuing…");

                        if ($sync) {
                              // Useful for small sites or CI pipelines. Runs the service
                              // inline (dispatch_sync() cannot return the job's stats) so
                              // real translated-vs-fallback counts reach the console.
                              // A failed file (driver error, 100% fallback) is reported and
                              // fails the command — never a silent "Done".
                              try {
                                    $stats = $this->translateFileInline($fileName, $locale, $force);
                              } catch (\Throwable $e) {
                                    $this->error("  ❌ Failed: lang/{$locale}/{$fileName}.php — {$e->getMessage()}");
                                    $failures++;
                                    $jobCount++;
                                    continue;
                              }

                              if ($stats === null) {
                                    $this->line("  ⏭️  Skipped (already exists): lang/{$locale}/{$fileName}.php");
                              } else {
                                    $summary = "{$stats['translated']}/{$stats['total']} translated";

                                    if ($stats['identical'] > 0) {
                                          $summary .= ", {$stats['identical']} identical to source";
                                    }

                                    $warnRatio = (float) config('lara-glot.fallback.warn_ratio', 0.5);

                                    if ($stats['total'] > 0 && $stats['ratio'] >= $warnRatio) {
                                          $this->warn("  ⚠️  Done with fallbacks: lang/{$locale}/{$fileName}.php ({$summary})");
                                    } else {
                                          $this->info("  ✅ Done: lang/{$locale}/{$fileName}.php ({$summary})");
                                    }
                              }
                        } else {
                              dispatch(new TranslateFilesJob($fileName, $locale, $force))->onQueue($queue);
                              $this->line("  ✉️  Job dispatched to [{$queue}]");
                        }

                        $jobCount++;
                  }
            }

            $this->newLine();

            if ($failures > 0) {
                  $this->error("💥 {$failures} of {$jobCount} job(s) failed. See messages above and storage/logs/laravel.log.");

                  return self::FAILURE;
            }

            $this->info($sync
                  ? "✨ {$jobCount} file(s) processed."
                  : "✨ {$jobCount} job(s) dispatched. Run your queue worker to process them.");

            return self::SUCCESS;
      }

      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Translate one file inline, mirroring TranslateFilesJob's skip logic.
       * Returns the fallback stats, or null when the file was skipped.
       */
      protected function translateFileInline(string $fileName, string $locale, bool $force): ?array
      {
            $targetPath = lang_path("{$locale}/{$fileName}.php");

            if (!$force && file_exists($targetPath)) {
                  return null;
            }

            return app(FileTranslationService::class)->translateFile($fileName, $locale);
      }

      /**
       * Return a plain array of file base-names (no extension) to translate.
       */
      protected function resolveFiles(): array
      {
            $sourceLocale = config('lara-glot.source_locale', 'en');
            $directory = lang_path($sourceLocale);

            if (!File::exists($directory)) {
                  $this->error("❌ Source directory not found: {$directory}");
                  return [];
            }

            // Single file argument supplied
            if ($specific = $this->argument('file')) {
                  return [str_replace('.php', '', $specific)];
            }

            // All .php files minus the exclusion list
            $exclude = config('lara-glot.exclude_files', []);

            return collect(File::files($directory))
                  ->filter(fn($f) => $f->getExtension() === 'php')
                  ->map(fn($f) => $f->getFilenameWithoutExtension())
                  ->reject(fn($name) => in_array($name, $exclude))
                  ->values()
                  ->all();
      }
}
