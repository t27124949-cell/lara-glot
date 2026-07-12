<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tonydev\LaraGlot\Services\FileTranslationService;
use Tonydev\LaraGlot\Services\TranslationAuditor;
use Tonydev\LaraGlot\Services\TranslationService;

/**
 * Eloquent stub exposing the same duck-typed surface the auditor relies on
 * (getTranslatableAttributes / getTranslations / setTranslations — the
 * subset of Spatie HasTranslations that LaraGlot uses).
 */
class AuditStubModel extends Model
{
      protected $table = 'audit_stub_models';
      protected $guarded = [];
      protected $casts = ['title' => 'array', 'slug' => 'array'];
      public $timestamps = false;

      public function getTranslatableAttributes(): array
      {
            return ['title', 'slug'];
      }

      public function getTranslations(string $attribute): array
      {
            return $this->getAttribute($attribute) ?? [];
      }

      public function setTranslations(string $attribute, array $translations): static
      {
            $this->setAttribute($attribute, $translations);

            return $this;
      }
}

function writeLangFile(string $locale, string $fileName, array $data): void
{
      $dir = lang_path($locale);
      @mkdir($dir, 0755, true);
      file_put_contents(
            "{$dir}/{$fileName}.php",
            "<?php\n\nreturn " . var_export($data, true) . ";\n"
      );
}

beforeEach(function () {
      app()->useLangPath(sys_get_temp_dir() . '/laraglot-audit-' . uniqid());

      $this->translator = Mockery::mock(TranslationService::class);
      $this->auditor = new TranslationAuditor(
            $this->translator,
            new FileTranslationService($this->translator)
      );
});

afterEach(fn() => Mockery::close());

// ── File audit ────────────────────────────────────────────────────────────────

it('counts identical and missing keys in a translated file', function () {
      writeLangFile('en', 'app', ['hello' => 'Hello', 'bye' => 'Goodbye', 'new' => 'Brand new']);
      writeLangFile('fr', 'app', ['hello' => 'Bonjour', 'bye' => 'Goodbye']); // 'new' missing

      $rows = $this->auditor->auditFiles(['fr']);

      expect($rows)->toHaveCount(1)
            ->and($rows[0]['eligible'])->toBe(3)
            ->and($rows[0]['identical'])->toBe(2) // 'bye' identical + 'new' missing
            ->and($rows[0]['ratio'])->toBe(0.6667);
});

it('treats a missing target file as fully untranslated', function () {
      writeLangFile('en', 'app', ['hello' => 'Hello', 'bye' => 'Goodbye']);

      $rows = $this->auditor->auditFiles(['ar']);

      expect($rows[0]['eligible'])->toBe(2)
            ->and($rows[0]['identical'])->toBe(2)
            ->and($rows[0]['ratio'])->toBe(1.0);
});

it('excludes allowlisted, protected, and ignored values from the audit', function () {
      config()->set('lara-glot.audit.allowlist', ['OK']);

      writeLangFile('en', 'app', [
            'confirm' => 'OK',                       // allowlisted
            'link' => 'route:contact',               // protected value
            'slug' => 'my-page',                     // ignored key
            'hello' => 'Hello',                      // the only auditable key
      ]);
      writeLangFile('fr', 'app', [
            'confirm' => 'OK',
            'link' => 'route:contact',
            'slug' => 'my-page',
            'hello' => 'Bonjour',
      ]);

      $rows = $this->auditor->auditFiles(['fr']);

      expect($rows[0]['eligible'])->toBe(1)
            ->and($rows[0]['identical'])->toBe(0);
});

it('repairs only the identical file values with a forced re-translation', function () {
      writeLangFile('en', 'app', ['hello' => 'Hello', 'bye' => 'Goodbye']);
      writeLangFile('fr', 'app', ['hello' => 'Bonjour', 'bye' => 'Goodbye']); // 'bye' fell back

      // Only 'bye' may be re-billed, and force must be true so a poisoned
      // cache entry is evicted instead of served back.
      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->with(['bye' => 'Goodbye'], 'fr', 'en', true)
            ->andReturn(['bye' => 'Au revoir']);

      $rows = $this->auditor->auditFiles(['fr'], repair: true);

      expect($rows[0]['repaired'])->toBe(1)
            ->and($rows[0]['identical'])->toBe(0);

      $written = require lang_path('fr/app.php');

      expect($written)->toBe(['hello' => 'Bonjour', 'bye' => 'Au revoir']);
});

it('keeps values flagged when repair still returns the source text', function () {
      writeLangFile('en', 'app', ['hello' => 'Hello']);
      writeLangFile('ar', 'app', ['hello' => 'Hello']);

      // Driver keeps failing — the forced re-translation comes back unchanged.
      $this->translator
            ->shouldReceive('translateBatch')
            ->once()
            ->andReturn(['hello' => 'Hello']);

      $rows = $this->auditor->auditFiles(['ar'], repair: true);

      expect($rows[0]['repaired'])->toBe(1)
            ->and($rows[0]['identical'])->toBe(1)
            ->and($rows[0]['ratio'])->toBe(1.0);
});

it('records an error and keeps the row when file repair throws', function () {
      writeLangFile('en', 'app', ['hello' => 'Hello']);

      $this->translator
            ->shouldReceive('translateBatch')
            ->andThrow(new \RuntimeException('Batch translation to [ar] failed'));

      $rows = $this->auditor->auditFiles(['ar'], repair: true);

      expect($rows[0]['error'])->toContain('failed')
            ->and($rows[0]['identical'])->toBe(1)
            ->and(file_exists(lang_path('ar/app.php')))->toBeFalse();
});

// ── Model audit ───────────────────────────────────────────────────────────────

function migrateAuditStubs(): void
{
      Schema::dropIfExists('audit_stub_models');
      Schema::create('audit_stub_models', function ($table) {
            $table->id();
            $table->json('title')->nullable();
            $table->json('slug')->nullable();
      });
}

it('audits model attributes against the source locale', function () {
      migrateAuditStubs();
      config()->set('lara-glot.models', [AuditStubModel::class]);

      AuditStubModel::create(['title' => ['en' => 'Hello', 'fr' => 'Bonjour']]);   // translated
      AuditStubModel::create(['title' => ['en' => 'Goodbye', 'fr' => 'Goodbye']]); // fell back
      AuditStubModel::create(['title' => ['en' => 'Missing']]);                    // never translated
      // 'slug' is in ignored_keys — must not count even when identical.
      AuditStubModel::create([
            'title' => ['en' => 'Fine', 'fr' => 'Bien'],
            'slug' => ['en' => 'fine', 'fr' => 'fine'],
      ]);

      $rows = $this->auditor->auditModels(['fr']);

      expect($rows)->toHaveCount(1)
            ->and($rows[0]['target'])->toBe(AuditStubModel::class)
            ->and($rows[0]['eligible'])->toBe(4)
            ->and($rows[0]['identical'])->toBe(2)
            ->and($rows[0]['ratio'])->toBe(0.5);
});

it('repairs only untranslated model attributes and saves the record', function () {
      migrateAuditStubs();
      config()->set('lara-glot.models', [AuditStubModel::class]);

      $record = AuditStubModel::create([
            'title' => ['en' => 'Goodbye', 'fr' => 'Goodbye'],
      ]);

      $this->translator
            ->shouldReceive('translate')
            ->once()
            ->with('Goodbye', 'fr', 'en', true)
            ->andReturn('Au revoir');

      $rows = $this->auditor->auditModels(['fr'], repair: true);

      expect($rows[0]['repaired'])->toBe(1)
            ->and($rows[0]['identical'])->toBe(0)
            ->and($record->fresh()->getTranslations('title'))
                  ->toBe(['en' => 'Goodbye', 'fr' => 'Au revoir']);
});

it('does not touch records whose repair still returns source text', function () {
      migrateAuditStubs();
      config()->set('lara-glot.models', [AuditStubModel::class]);

      $record = AuditStubModel::create(['title' => ['en' => 'Acme', 'fr' => 'Acme']]);

      $this->translator
            ->shouldReceive('translate')
            ->once()
            ->andReturn('Acme');

      $rows = $this->auditor->auditModels(['fr'], repair: true);

      expect($rows[0]['identical'])->toBe(1)
            ->and($record->fresh()->getTranslations('title'))->toBe(['en' => 'Acme', 'fr' => 'Acme']);
});
