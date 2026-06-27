<?php

namespace Tonydev\LaraGlot\Traits;

use Tonydev\LaraGlot\Services\ModelTranslationManager;

/**
 * Marks a model as supporting LaraGlot translations.
 *
 * This trait is intentionally explicit — translation is never dispatched
 * automatically on model save. The caller decides when to translate.
 *
 * WHY NOT AUTO-DISPATCH ON SAVED?
 *  - The `saved` event fires on every `saveQuietly()` call inside the
 *    translation pipeline itself, causing infinite dispatch loops.
 *  - Runtime flags (e.g. $translating = true) don't survive queue
 *    serialization, so loop-guards break across processes.
 *  - Explicit dispatch is easier to debug, test, and reason about.
 *  - Callers control timing — after form submission, via Artisan, or
 *    from a Filament action — not hidden inside model lifecycle hooks.
 *
 * USAGE:
 *
 *   // Synchronous (blocking — use in Artisan commands or tests)
 *   $page->translateNow();
 *   $page->translateNow(force: true, locales: ['fr', 'de']);
 *
 *   // Asynchronous (queued — use in controllers and Filament actions)
 *   $page->translateLater();
 *   $page->translateLater(force: true, locales: ['es'], delay: 10);
 *
 *   // Or inject ModelTranslationManager directly for batch operations:
 *   app(ModelTranslationManager::class)->translateBatch($records, locales: ['fr']);
 */
trait HasSmartTranslations
{
      /**
       * Translate this model synchronously in the current process (blocking).
       *
       * Use in:
       *  - Artisan commands where you want immediate results
       *  - Tests that assert on translated content
       *  - One-off admin scripts
       *
       * @param bool   $force    Re-translate even if translations already exist.
       * @param array  $locales  Target locale codes. Empty = all configured locales.
       */
      public function translateNow(bool $force = false, array $locales = []): void
      {
            app(ModelTranslationManager::class)->translateSync($this, $force, $locales);
      }

      /**
       * Dispatch a translation job to the queue (non-blocking).
       *
       * Use in:
       *  - Controllers after a model is created or updated
       *  - Filament resource actions
       *  - Event listeners
       *
       * @param bool   $force    Re-translate even if translations already exist.
       * @param array  $locales  Target locale codes. Empty = all configured locales.
       * @param int    $delay    Seconds to delay before the job is processed.
       */
      public function translateLater(bool $force = false, array $locales = [], int $delay = 0): void
      {
            app(ModelTranslationManager::class)->translateAsync($this, $force, $locales, $delay);
      }
}
