<?php

namespace Tonydev\LaraGlot\Commands;

use Illuminate\Console\Command;
use Tonydev\LaraGlot\Services\TranslationAuditor;

class AuditTranslationsCommand extends Command
{
      protected $signature = 'laraglot:audit
                              {--locale=* : Only audit these locale(s) (e.g. --locale=ar --locale=de)}
                              {--file=*   : Only audit these language file(s) (base name, e.g. footer)}
                              {--model=*  : Only audit these model class(es)}
                              {--files-only  : Audit language files only}
                              {--models-only : Audit registered models only}
                              {--repair      : Re-translate (force) every value still identical to the source}
                              {--threshold=  : Flag ratio 0–1 (default: lara-glot.audit.threshold)}';

      protected $description = 'Report language-file keys and model attributes whose value is identical to the source locale — the signature of a silent translation fallback — and optionally repair them';

      public function handle(TranslationAuditor $auditor): int
      {
            $sourceLocale = config('lara-glot.source_locale', 'en');
            $languages = config('lara-glot.languages', []);

            if (empty($languages)) {
                  $this->error('❌ No languages defined in config/lara-glot.php');
                  return self::FAILURE;
            }

            $locales = collect($languages)
                  ->keys()
                  ->reject(fn($locale) => $locale === $sourceLocale)
                  ->when(
                        !empty($this->option('locale')),
                        fn($col) => $col->filter(fn($l) => in_array($l, $this->option('locale'), true))
                  )
                  ->values()
                  ->all();

            if (empty($locales)) {
                  $this->error('❌ No valid target locales found. Check --locale value or lara-glot config.');
                  return self::FAILURE;
            }

            $threshold = $this->option('threshold') !== null
                  ? (float) $this->option('threshold')
                  : (float) config('lara-glot.audit.threshold', 0.85);

            $repair = (bool) $this->option('repair');

            $this->info(sprintf(
                  '🔍 Auditing %d locale(s) against [%s]%s (flag threshold: %d%%)',
                  count($locales),
                  $sourceLocale,
                  $repair ? ' with repair' : '',
                  (int) round($threshold * 100)
            ));

            $rows = [];

            if (!$this->option('models-only')) {
                  $rows = array_merge($rows, $auditor->auditFiles(
                        $locales,
                        (array) $this->option('file'),
                        $repair
                  ));
            }

            if (!$this->option('files-only')) {
                  $rows = array_merge($rows, $auditor->auditModels(
                        $locales,
                        (array) $this->option('model'),
                        $repair
                  ));
            }

            if (empty($rows)) {
                  $this->warn('🤔 Nothing to audit — no language files or registered models found.');
                  return self::SUCCESS;
            }

            $flagged = 0;
            $errors = 0;

            $this->table(
                  ['Type', 'Target', 'Locale', 'Eligible', 'Identical', 'Ratio', $repair ? 'Repaired' : 'Status'],
                  array_map(function (array $row) use ($threshold, $repair, &$flagged, &$errors) {
                        $isFlagged = $row['eligible'] > 0 && $row['ratio'] >= $threshold;

                        if ($isFlagged) {
                              $flagged++;
                        }

                        if ($row['error'] !== null) {
                              $errors++;
                        }

                        $status = match (true) {
                              $row['error'] !== null => '💥 error',
                              $isFlagged => '🚨 FLAGGED',
                              $row['identical'] > 0 => '⚠️  partial',
                              default => '✅ ok',
                        };

                        return [
                              $row['type'],
                              $row['target'],
                              $row['locale'],
                              $row['eligible'],
                              $row['identical'],
                              sprintf('%d%%', (int) round($row['ratio'] * 100)),
                              $repair ? "{$row['repaired']} ({$status})" : $status,
                        ];
                  }, $rows)
            );

            $identicalTotal = array_sum(array_column($rows, 'identical'));
            $repairedTotal = array_sum(array_column($rows, 'repaired'));

            if ($repair) {
                  $this->info("🔧 {$repairedTotal} value(s) re-translated; {$identicalTotal} still identical to source.");

                  if ($identicalTotal > 0) {
                        $this->line('   Values that stay identical after repair are either legitimately identical');
                        $this->line('   (add them to lara-glot.audit.allowlist) or the driver is still failing (check logs).');
                  }
            }

            if ($errors > 0) {
                  $this->error("💥 {$errors} target(s) errored during the audit. See storage/logs/laravel.log.");
                  return self::FAILURE;
            }

            if ($flagged > 0) {
                  $this->error(sprintf(
                        '🚨 %d target/locale pair(s) are ≥%d%% identical to the source%s',
                        $flagged,
                        (int) round($threshold * 100),
                        $repair ? ' even after repair.' : ' — run with --repair to re-translate only those values.'
                  ));
                  return self::FAILURE;
            }

            $this->info('✅ Audit passed — no silent fallbacks above the threshold.');

            return self::SUCCESS;
      }
}
