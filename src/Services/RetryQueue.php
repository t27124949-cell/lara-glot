<?php

namespace Tonydev\LaraGlot\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tonydev\LaraGlot\Models\TranslationRetry;

/**
 * Durable dead-letter state for translation units that fell back to source
 * text ("never silently fall back" — the fallback itself is acceptable, the
 * silence is not).
 *
 * Recording is best-effort by design: when the retry table has not been
 * migrated (or the DB is unavailable), translation must keep working —
 * every write is guarded and degrades to a single logged notice.
 */
class RetryQueue
{
      /**
       * Set after the first failed DB interaction so the notice is logged only
       * once per instance (the container binds this as a singleton, so in
       * practice once per process).
       */
      protected bool $unavailable = false;

      /**
       * Record (or refresh) a fallback unit as pending.
       *
       * Re-recording an existing pending unit does NOT reset its attempt
       * count — otherwise every translation run would resurrect exhausted
       * units. A changed source text does reset it: the old failure is stale.
       */
      public function record(
            string $type,
            string $target,
            string $itemKey,
            string $locale,
            string $sourceText,
            ?string $error = null
      ): void {
            $this->guarded(function () use ($type, $target, $itemKey, $locale, $sourceText, $error) {
                  $hash = hash('sha256', $sourceText);

                  $unit = TranslationRetry::query()->firstOrNew([
                        'type' => $type,
                        'target' => $target,
                        'item_key' => $itemKey,
                        'locale' => $locale,
                  ]);

                  if ($unit->exists && $unit->source_hash === $hash) {
                        // Same failing unit seen again — keep its attempt
                        // history, just make sure resolved units reopen.
                        if ($unit->status === TranslationRetry::STATUS_RESOLVED) {
                              $unit->status = TranslationRetry::STATUS_PENDING;
                              $unit->next_retry_at = now();
                        }
                  } else {
                        // New unit, or the source text changed since we last
                        // saw it — start a fresh retry cycle.
                        $unit->source_hash = $hash;
                        $unit->attempts = 0;
                        $unit->status = TranslationRetry::STATUS_PENDING;
                        $unit->next_retry_at = now();
                  }

                  if ($error !== null) {
                        $unit->last_error = mb_substr($error, 0, 2000);
                  }

                  $unit->save();
            });
      }

      /**
       * Units eligible for a retry right now, oldest first.
       *
       * @return Collection<int, TranslationRetry>
       */
      public function due(int $limit = 500): Collection
      {
            return $this->guarded(
                  fn() => TranslationRetry::query()
                        ->due()
                        ->orderBy('next_retry_at')
                        ->limit($limit)
                        ->get()
            ) ?? collect();
      }

      /** Count of units past the attempt cap, awaiting human review. */
      public function exhaustedCount(): int
      {
            return (int) ($this->guarded(
                  fn() => TranslationRetry::query()
                        ->where('status', TranslationRetry::STATUS_EXHAUSTED)
                        ->count()
            ) ?? 0);
      }

      public function markResolved(TranslationRetry $unit): void
      {
            $this->guarded(function () use ($unit) {
                  $unit->forceFill([
                        'status' => TranslationRetry::STATUS_RESOLVED,
                        'last_error' => null,
                  ])->save();
            });
      }

      /**
       * Record a failed attempt: exponential back-off between retries
       * (base_delay_minutes × 2^attempts) and hard cap at max_attempts,
       * after which the unit is parked as exhausted for human review.
       */
      public function markAttemptFailed(TranslationRetry $unit, ?string $error = null): void
      {
            $this->guarded(function () use ($unit, $error) {
                  $maxAttempts = max(1, (int) config('lara-glot.retry.max_attempts', 5));
                  $baseDelay = max(1, (int) config('lara-glot.retry.base_delay_minutes', 30));

                  $attempts = $unit->attempts + 1;

                  $unit->forceFill([
                        'attempts' => $attempts,
                        'last_error' => $error !== null ? mb_substr($error, 0, 2000) : $unit->last_error,
                        'status' => $attempts >= $maxAttempts
                              ? TranslationRetry::STATUS_EXHAUSTED
                              : TranslationRetry::STATUS_PENDING,
                        'next_retry_at' => now()->addMinutes($baseDelay * (2 ** $attempts)),
                  ])->save();
            });
      }

      /**
       * Run a DB interaction, degrading to a no-op (null) when the retry
       * table is missing or the connection is down. Logged once per process.
       */
      protected function guarded(callable $operation): mixed
      {
            if ($this->unavailable) {
                  return null;
            }

            try {
                  return $operation();
            } catch (\Throwable $e) {
                  $this->unavailable = true;

                  Log::notice(
                        '[LaraGlot] Retry queue unavailable — fallbacks will not be tracked. '
                              . 'Run `php artisan migrate` to create the lara_glot_translation_retries table.',
                        ['error' => $e->getMessage()]
                  );

                  return null;
            }
      }
}
