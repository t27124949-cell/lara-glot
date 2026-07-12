<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tonydev\LaraGlot\Contracts\TranslationDriverInterface;
use Tonydev\LaraGlot\Models\TranslationRetry;
use Tonydev\LaraGlot\Services\FileTranslationService;
use Tonydev\LaraGlot\Services\RetryQueue;
use Tonydev\LaraGlot\Services\SmartTranslationService;
use Tonydev\LaraGlot\Services\TranslationService;

class RetryStubModel extends Model
{
      protected $table = 'retry_stub_models';
      protected $guarded = [];
      protected $casts = ['title' => 'array'];
      public $timestamps = false;

      public function getTranslatableAttributes(): array
      {
            return ['title'];
      }

      public function isTranslatableAttribute(string $attribute): bool
      {
            return in_array($attribute, $this->getTranslatableAttributes(), true);
      }

      public function getTranslation(string $attribute, string $locale): ?string
      {
            return $this->getTranslations($attribute)[$locale] ?? null;
      }

      public function setTranslation(string $attribute, string $locale, string $value): static
      {
            $translations = $this->getTranslations($attribute);
            $translations[$locale] = $value;

            return $this->setTranslations($attribute, $translations);
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

function writeRetryLangFile(string $locale, string $fileName, array $data): void
{
      $dir = lang_path($locale);
      @mkdir($dir, 0755, true);
      file_put_contents(
            "{$dir}/{$fileName}.php",
            "<?php\n\nreturn " . var_export($data, true) . ";\n"
      );
}

function useDriver(string $name, callable $translate): void
{
      TranslationService::extend($name, fn() => new class ($translate) implements TranslationDriverInterface {
            public function __construct(private $fn)
            {
            }

            public function translate(string $text, string $target, string $source = 'en'): string
            {
                  return ($this->fn)($text, $target);
            }

            public function translateBatch(array $texts, string $target, string $source = 'en'): array
            {
                  return array_map(
                        fn($t) => is_string($t) ? ($this->fn)($t, $target) : $t,
                        $texts
                  );
            }
      });

      config(['lara-glot.translator' => $name]);
}

beforeEach(function () {
      $this->artisan('migrate')->run();
      app()->useLangPath(sys_get_temp_dir() . '/laraglot-retry-' . uniqid());
      $this->queue = new RetryQueue();
});

// ── File units ────────────────────────────────────────────────────────────────

it('heals a pending file unit and marks it resolved', function () {
      useDriver('retry-ok', fn($text, $target) => "[{$target}] {$text}");

      writeRetryLangFile('en', 'footer', ['contact' => 'Contact us', 'legal' => 'Legal']);
      writeRetryLangFile('ar', 'footer', ['contact' => 'Contact us', 'legal' => 'قانوني']);

      $this->queue->record('file', 'footer', 'contact', 'ar', 'Contact us');

      $this->artisan('laraglot:retry')->assertExitCode(0);

      $written = require lang_path('ar/footer.php');

      expect($written['contact'])->toBe('[ar] Contact us')
            ->and($written['legal'])->toBe('قانوني') // existing translation untouched
            ->and(TranslationRetry::sole()->status)->toBe(TranslationRetry::STATUS_RESOLVED);
});

it('reschedules with back-off when re-translation is still identical, and exits non-zero once exhausted', function () {
      config()->set('lara-glot.retry.max_attempts', 1);
      useDriver('retry-broken', fn($text) => $text); // still failing

      writeRetryLangFile('en', 'footer', ['contact' => 'Contact us']);
      writeRetryLangFile('ar', 'footer', ['contact' => 'Contact us']);

      $this->queue->record('file', 'footer', 'contact', 'ar', 'Contact us');

      // One attempt allowed → this run exhausts the unit → surfaced via exit 1.
      $this->artisan('laraglot:retry')->assertExitCode(1);

      $unit = TranslationRetry::sole();

      expect($unit->status)->toBe(TranslationRetry::STATUS_EXHAUSTED)
            ->and($unit->attempts)->toBe(1)
            ->and($unit->last_error)->toContain('identical');
});

it('resolves stale units without spending an API call', function () {
      useDriver('retry-spy', function ($text) {
            throw new \RuntimeException('driver must not be called for stale units');
      });

      writeRetryLangFile('en', 'footer', ['contact' => 'Reach our team']); // source changed

      $this->queue->record('file', 'footer', 'contact', 'ar', 'Contact us');

      $this->artisan('laraglot:retry')->assertExitCode(0);

      expect(TranslationRetry::sole()->status)->toBe(TranslationRetry::STATUS_RESOLVED);
});

it('resolves units already healed elsewhere without spending an API call', function () {
      useDriver('retry-spy2', function ($text) {
            throw new \RuntimeException('driver must not be called for healed units');
      });

      writeRetryLangFile('en', 'footer', ['contact' => 'Contact us']);
      writeRetryLangFile('ar', 'footer', ['contact' => 'اتصل بنا']); // audit --repair fixed it

      $this->queue->record('file', 'footer', 'contact', 'ar', 'Contact us');

      $this->artisan('laraglot:retry')->assertExitCode(0);

      expect(TranslationRetry::sole()->status)->toBe(TranslationRetry::STATUS_RESOLVED);
});

// ── Model units ───────────────────────────────────────────────────────────────

it('heals a pending model unit and saves the record', function () {
      Schema::dropIfExists('retry_stub_models');
      Schema::create('retry_stub_models', function ($table) {
            $table->id();
            $table->json('title')->nullable();
      });

      useDriver('retry-model-ok', fn($text, $target) => "[{$target}] {$text}");

      $record = RetryStubModel::create(['title' => ['en' => 'Goodbye', 'ar' => 'Goodbye']]);

      $this->queue->record('model', RetryStubModel::class, "{$record->id}:title", 'ar', 'Goodbye');

      $this->artisan('laraglot:retry')->assertExitCode(0);

      expect($record->fresh()->getTranslations('title'))
            ->toBe(['en' => 'Goodbye', 'ar' => '[ar] Goodbye'])
            ->and(TranslationRetry::sole()->status)->toBe(TranslationRetry::STATUS_RESOLVED);
});

// ── Recording hooks ───────────────────────────────────────────────────────────

it('records a retry unit when a file value silently falls back during translation', function () {
      // 'Stubborn' comes back identical (partial fallback); the rest translate.
      useDriver('retry-partial', fn($text, $target) => $text === 'Stubborn' ? $text : "[{$target}] {$text}");

      writeRetryLangFile('en', 'footer', ['a' => 'Hello', 'b' => 'Stubborn']);

      $service = new FileTranslationService(new TranslationService(), $this->queue);
      $stats = $service->translateFile('footer', 'ar');

      expect($stats['identical'])->toBe(1);

      $unit = TranslationRetry::sole();

      expect($unit->type)->toBe('file')
            ->and($unit->target)->toBe('footer')
            ->and($unit->item_key)->toBe('b')
            ->and($unit->locale)->toBe('ar')
            ->and($unit->status)->toBe(TranslationRetry::STATUS_PENDING);
});

it('records retry units when model attributes stay untranslated', function () {
      config()->set('concurrency.default', 'sync');
      config()->set('lara-glot.languages', [
            'en' => ['name' => 'English'],
            'ar' => ['name' => 'العربية'],
      ]);

      Schema::dropIfExists('retry_stub_models');
      Schema::create('retry_stub_models', function ($table) {
            $table->id();
            $table->json('title')->nullable();
      });

      useDriver('retry-model-broken', fn($text) => $text); // everything falls back

      $record = RetryStubModel::create(['title' => ['en' => 'Goodbye']]);

      $service = new SmartTranslationService(new TranslationService(), $this->queue);
      $service->translateModel($record);

      $unit = TranslationRetry::sole();

      expect($unit->type)->toBe('model')
            ->and($unit->target)->toBe(RetryStubModel::class)
            ->and($unit->item_key)->toBe("{$record->id}:title")
            ->and($unit->locale)->toBe('ar')
            ->and($unit->status)->toBe(TranslationRetry::STATUS_PENDING);
});
