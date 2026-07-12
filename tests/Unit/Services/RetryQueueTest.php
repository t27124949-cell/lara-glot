<?php

use Tonydev\LaraGlot\Models\TranslationRetry;
use Tonydev\LaraGlot\Services\RetryQueue;

beforeEach(function () {
      $this->artisan('migrate')->run();
      $this->queue = new RetryQueue();
});

it('records a new fallback unit as pending and immediately due', function () {
      $this->queue->record('file', 'footer', 'contact.title', 'ar', 'Contact us');

      $unit = TranslationRetry::sole();

      expect($unit->status)->toBe(TranslationRetry::STATUS_PENDING)
            ->and($unit->attempts)->toBe(0)
            ->and($unit->source_hash)->toBe(hash('sha256', 'Contact us'))
            ->and($this->queue->due()->pluck('id')->all())->toBe([$unit->id]);
});

it('does not reset attempts when the same failing unit is recorded again', function () {
      $this->queue->record('file', 'footer', 'contact.title', 'ar', 'Contact us');
      $this->queue->markAttemptFailed(TranslationRetry::sole(), 'still English');

      // Another translation run sees the same fallback.
      $this->queue->record('file', 'footer', 'contact.title', 'ar', 'Contact us');

      expect(TranslationRetry::sole()->attempts)->toBe(1);
});

it('starts a fresh cycle when the source text changed', function () {
      $this->queue->record('file', 'footer', 'contact.title', 'ar', 'Contact us');
      $this->queue->markAttemptFailed(TranslationRetry::sole(), 'still English');

      $this->queue->record('file', 'footer', 'contact.title', 'ar', 'Contact us today');

      $unit = TranslationRetry::sole();

      expect($unit->attempts)->toBe(0)
            ->and($unit->source_hash)->toBe(hash('sha256', 'Contact us today'))
            ->and($unit->status)->toBe(TranslationRetry::STATUS_PENDING);
});

it('applies exponential back-off and parks the unit as exhausted at the cap', function () {
      config()->set('lara-glot.retry.max_attempts', 3);
      config()->set('lara-glot.retry.base_delay_minutes', 10);

      $this->queue->record('file', 'footer', 'contact.title', 'ar', 'Contact us');
      $unit = TranslationRetry::sole();

      $this->queue->markAttemptFailed($unit, 'fail 1');

      expect($unit->refresh()->status)->toBe(TranslationRetry::STATUS_PENDING)
            // 10 × 2^1 = 20 minutes out — no longer due.
            ->and($unit->next_retry_at->isAfter(now()->addMinutes(15)))->toBeTrue()
            ->and($this->queue->due())->toHaveCount(0);

      $this->queue->markAttemptFailed($unit->refresh(), 'fail 2');
      $this->queue->markAttemptFailed($unit->refresh(), 'fail 3');

      expect($unit->refresh()->status)->toBe(TranslationRetry::STATUS_EXHAUSTED)
            ->and($this->queue->exhaustedCount())->toBe(1);
});

it('reopens a resolved unit when the same fallback is recorded again', function () {
      $this->queue->record('file', 'footer', 'contact.title', 'ar', 'Contact us');
      $this->queue->markResolved(TranslationRetry::sole());

      $this->queue->record('file', 'footer', 'contact.title', 'ar', 'Contact us');

      expect(TranslationRetry::sole()->status)->toBe(TranslationRetry::STATUS_PENDING);
});

it('degrades to a no-op when the retry table is missing', function () {
      Illuminate\Support\Facades\Schema::drop('lara_glot_translation_retries');

      $queue = new RetryQueue();

      // Must not throw — translation keeps working without tracking.
      $queue->record('file', 'footer', 'contact.title', 'ar', 'Contact us');

      expect($queue->due())->toHaveCount(0)
            ->and($queue->exhaustedCount())->toBe(0);
});
