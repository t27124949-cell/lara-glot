<?php

namespace Tonydev\LaraGlot\Concerns;

use Illuminate\Support\Facades\Concurrency;

/**
 * Fan out callables through Laravel's Concurrency::run() with results that
 * survive every concurrency driver.
 *
 * Laravel's default `process` driver ships each task's return value back
 * through the child's console output, and raw multibyte payloads (Arabic,
 * Chinese, …) get mangled in transit, breaking serialize()'s byte-length
 * framing — the parent then dies with "unserialize(): Error at offset …" and
 * whole batches fall back to source text. Wrapping every result in
 * base64(serialize(…)) keeps the transported payload pure ASCII.
 *
 * Concurrency::run() also returns a sequential 0-indexed list, NOT keyed by
 * the original task keys, so original keys are captured and re-applied here.
 */
trait TransportsConcurrencyResults
{
      /**
       * @param  array<string|int, callable> $tasks
       * @param  int                         $batchSize
       * @return array<string|int, mixed>
       */
      protected function runConcurrentBatches(array $tasks, int $batchSize): array
      {
            $results = [];

            foreach (array_chunk($tasks, $batchSize, true) as $batch) {
                  $originalKeys = array_keys($batch);

                  // Wrap each task so its result crosses the process boundary
                  // as ASCII-safe base64 (see trait docblock).
                  $wrapped = array_map(
                        static fn(callable $task): callable =>
                              static fn(): string => base64_encode(serialize($task())),
                        array_values($batch)
                  );

                  $batchResults = Concurrency::run($wrapped);

                  foreach ($batchResults as $index => $value) {
                        $results[$originalKeys[$index]] = unserialize(
                              base64_decode($value),
                              ['allowed_classes' => false]
                        );
                  }
            }

            return $results;
      }
}
