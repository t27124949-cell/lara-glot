<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Log;

/**
 * Base class for prompt-driven (LLM) translation drivers.
 *
 * Owns the full chunked-translation protocol shared by OpenAI, Anthropic,
 * and Ollama: string-level caching, chunk splitting, concurrent fan-out,
 * placeholder and glossary-term protection, the JSON request/response
 * contract, the optional quality-review pass, and fallback to originals.
 *
 * Concrete drivers implement two things:
 *  - driverName()  — the config key ('openai', 'anthropic', 'ollama')
 *  - sendChunk()   — one raw API round-trip returning the model's text output
 */
abstract class AbstractLlmDriver extends AbstractTranslationDriver
{
      /** Model identifier, resolved from config by the concrete driver. */
      protected string $model;

      /**
       * Strings per API call. Keep low enough that prompt + response fit
       * inside the model's context window.
       */
      protected int $chunkSize = 25;

      /** Memoized cache-key salt covering glossary + prompt config. */
      private ?string $promptFingerprint = null;

      /**
       * Send one chunk to the provider and return the model's raw text output.
       * Must throw on any transport or API error so withRetry() can re-attempt.
       */
      abstract protected function sendChunk(string $jsonInput, string $systemPrompt): string;

      // ── Batch translation ────────────────────────────────────────────────────

      /**
       * Translate a map of strings, serving individual cache hits first and
       * fanning uncached chunks out concurrently.
       *
       * Two cost guarantees:
       *  - Per-string caching: a single changed string never invalidates its
       *    whole chunk, and nothing cached is ever re-sent to the API.
       *  - In-batch deduplication: identical strings appearing under multiple
       *    keys are sent to the API exactly once and fanned back out.
       *
       * @param  array<int|string, string> $texts
       * @return array<int|string, string>
       */
      public function translateBatch(
            array $texts,
            string $target,
            string $source = 'en'
      ): array {
            $results = [];
            $unique = [];       // text-hash => text, one entry per distinct string
            $keysByHash = [];   // text-hash => list of original keys wanting it

            foreach ($texts as $key => $text) {
                  if (!is_string($text) || trim($text) === '') {
                        $results[$key] = $text;
                        continue;
                  }

                  $cached = $this->getFromCache($this->getCacheKey($text, $target, $source));

                  if ($cached !== null) {
                        $results[$key] = $cached;
                        continue;
                  }

                  $hash = hash('xxh128', $text);
                  $unique[$hash] = $text;
                  $keysByHash[$hash][] = $key;
            }

            if (empty($unique)) {
                  ksort($results);
                  return $results;
            }

            $chunks = array_chunk($unique, $this->chunkSize, true);
            $tasks = [];

            foreach ($chunks as $index => $chunk) {
                  $tasks[$index] = fn() => $this->translateChunk($chunk, $target, $source);
            }

            $batchResults = $this->runConcurrentBatches($tasks, $this->concurrencyLimit);

            foreach ($batchResults as $chunkOutput) {
                  if (!is_array($chunkOutput)) {
                        continue;
                  }

                  foreach ($chunkOutput as $hash => $translated) {
                        if (is_string($translated)) {
                              // Cache against the original text so future lookups hit.
                              $this->putInCache(
                                    $this->getCacheKey($unique[$hash], $target, $source),
                                    $translated
                              );
                        }

                        // Fan the single translation out to every key that wanted it.
                        foreach ($keysByHash[$hash] ?? [] as $key) {
                              $results[$key] = $translated;
                        }
                  }
            }

            ksort($results);

            return $results;
      }

      // ── Single chunk ─────────────────────────────────────────────────────────

      /**
       * Translate one chunk: protect placeholders, send, validate, optionally
       * review, restore placeholders. Falls back to the original strings on
       * permanent failure — callers never see an exception or a raw token.
       *
       * @param  array<int|string, string> $chunk
       * @return array<int|string, string>
       */
      protected function translateChunk(
            array $chunk,
            string $target,
            string $source
      ): array {
            $keys = array_keys($chunk);
            $values = array_values($chunk);

            // Placeholder protection is the hard guarantee; the system prompt
            // only reinforces it.
            $protectedValues = [];
            $placeholderMaps = [];

            foreach ($values as $i => $text) {
                  [$protectedValues[$i], $placeholderMaps[$i]] = $this->protectPlaceholders($text);
            }

            Log::info("{$this->logTag()} Sending chunk of " . count($chunk) . " string(s) → {$target}");

            // A JSON encoding failure is not retryable — bail out immediately.
            try {
                  $jsonInput = json_encode(
                        $protectedValues,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                  );
            } catch (\JsonException $e) {
                  Log::error("{$this->logTag()} Failed to JSON-encode chunk.", [
                        'error' => $e->getMessage(),
                  ]);

                  return array_combine($keys, $values);
            }

            $systemPrompt = $this->buildSystemPrompt($source, $target);

            try {
                  $translated = $this->withRetry(
                        function () use ($protectedValues, $jsonInput, $systemPrompt): array {
                              $this->recordApiCall();

                              $raw = trim($this->sendChunk($jsonInput, $systemPrompt));

                              $decoded = $this->decodeJsonResponse($raw, $this->logName ?: $this->driverName());

                              if (!is_array($decoded) || count($decoded) !== count($protectedValues)) {
                                    throw new \RuntimeException(sprintf(
                                          '%s returned %s result(s) for %d input(s). Raw: %s',
                                          $this->driverName(),
                                          is_array($decoded) ? count($decoded) : 'null',
                                          count($protectedValues),
                                          mb_substr($raw, 0, 300)
                                    ));
                              }

                              return array_map(fn($v) => $this->normalizeTranslated($v), $decoded);
                        },
                        $this->maxRetries,
                        $this->retryDelayMs,
                        ':' . ($this->logName ?: ucfirst($this->driverName()))
                  );

                  // Optional second pass. Runs on protected text so locked
                  // tokens survive; failures keep the first draft.
                  if ($this->reviewEnabled()) {
                        $translated = $this->reviewChunk($protectedValues, $translated, $source, $target);
                  }

                  $mapped = [];

                  foreach ($keys as $i => $originalKey) {
                        $mapped[$originalKey] = $this->restorePlaceholders(
                              $translated[$i],
                              $placeholderMaps[$i]
                        );
                  }

                  return $mapped;

            } catch (\Throwable $e) {
                  Log::error(
                        "{$this->logTag()} Chunk failed permanently after {$this->maxRetries} attempt(s).",
                        ['error' => $e->getMessage()]
                  );

                  // Restore placeholders on the originals so callers never
                  // receive raw __VAR_0__ tokens, even on the fallback path.
                  $fallback = [];

                  foreach ($keys as $i => $originalKey) {
                        $fallback[$originalKey] = $this->restorePlaceholders(
                              $values[$i] ?? '',
                              $placeholderMaps[$i]
                        );
                  }

                  return $fallback;
            }
      }

      // ── Quality review pass ──────────────────────────────────────────────────

      protected function reviewEnabled(): bool
      {
            return (bool) config('lara-glot.review.enabled', false);
      }

      /**
       * Second API call that reviews draft translations for naturalness.
       * Receives source/draft pairs, returns a corrected list of the same
       * length. Any failure logs a warning and keeps the drafts.
       *
       * @param  list<string> $sources  Protected source strings.
       * @param  list<string> $drafts   Protected draft translations.
       * @return list<string>
       */
      protected function reviewChunk(array $sources, array $drafts, string $source, string $target): array
      {
            try {
                  $pairs = [];

                  foreach ($sources as $i => $sourceText) {
                        $pairs[] = ['source' => $sourceText, 'draft' => $drafts[$i]];
                  }

                  $jsonInput = json_encode(
                        $pairs,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                  );

                  $this->recordApiCall();

                  $raw = trim($this->sendChunk($jsonInput, $this->buildReviewPrompt($source, $target)));

                  $decoded = $this->decodeJsonResponse($raw, $this->driverName() . ':review');

                  if (!is_array($decoded) || count($decoded) !== count($drafts)) {
                        throw new \RuntimeException(sprintf(
                              'Review returned %s result(s) for %d draft(s).',
                              is_array($decoded) ? count($decoded) : 'null',
                              count($drafts)
                        ));
                  }

                  return array_map(fn($v) => $this->normalizeTranslated($v), $decoded);

            } catch (\Throwable $e) {
                  Log::warning("{$this->logTag()} Review pass failed — keeping first draft.", [
                        'error' => $e->getMessage(),
                  ]);

                  return $drafts;
            }
      }

      // ── Prompts ──────────────────────────────────────────────────────────────

      /**
       * System prompt for the translation call. A string set in
       * `lara-glot.prompt` replaces it entirely ({source}/{target} substituted).
       */
      protected function buildSystemPrompt(string $source, string $target): string
      {
            $override = config('lara-glot.prompt');

            if (is_string($override) && trim($override) !== '') {
                  return strtr($override, ['{source}' => $source, '{target}' => $target]);
            }

            $glossaryDirectives = $this->glossaryDirectives($target);

            return <<<PROMPT
You are a senior native-speaker translator localizing a software product from "{$source}" to "{$target}".

TRANSLATION QUALITY:
- Translate meaning, not words. The result must read as if originally written in {$target} by a native speaker.
- Match the register and tone of the source: keep UI labels terse, keep marketing copy warm, keep error messages clear and direct.
- Use the vocabulary real products in this language actually use — not textbook or machine-translation phrasing.
- Never pad, embellish, or explain. Output only the translation.

STRUCTURE (non-negotiable):
- Respond with ONLY a valid JSON array of strings — no markdown fences, no commentary, no wrapper object.
- Preserve the exact element count and order.
- Do not translate or alter opaque tokens: __HTML_N__, __URL_N__, __VAR_N__, __BRACE_N__, __TERM_N__, or Laravel placeholders like :name and :count.
- Preserve all HTML tags and attributes exactly as written.
{$glossaryDirectives}
PROMPT;
      }

      /**
       * System prompt for the review pass.
       */
      protected function buildReviewPrompt(string $source, string $target): string
      {
            $glossaryDirectives = $this->glossaryDirectives($target);

            return <<<PROMPT
You are a native {$target} speaker reviewing draft translations from "{$source}" for a software product.

You receive a JSON array of objects, each with "source" (the original) and "draft" (the translation to review).

For each pair, return the best final translation:
- Fix phrasing that is overly literal, stiff, or reads like machine output.
- Replace vocabulary a native speaker would not use in a real product.
- Match the register of the source (terse UI label, warm marketing copy, direct error message).
- If the draft is already natural and accurate, return it unchanged.
- Never touch opaque tokens (__HTML_N__, __URL_N__, __VAR_N__, __BRACE_N__, __TERM_N__), :placeholders, or HTML tags.

Respond with ONLY a valid JSON array of strings — one final translation per pair, same order, same count. No commentary.
{$glossaryDirectives}
PROMPT;
      }

      /**
       * Forced-translation directives for glossary terms that have an entry
       * for the current target locale. Empty string when none apply.
       */
      protected function glossaryDirectives(string $target): string
      {
            $terms = (array) config('lara-glot.glossary.terms', []);
            $lines = [];

            foreach ($terms as $term => $translations) {
                  if (is_array($translations) && isset($translations[$target])) {
                        $lines[] = sprintf('- Always translate "%s" as "%s".', $term, $translations[$target]);
                  }
            }

            if (empty($lines)) {
                  return '';
            }

            return "\nGLOSSARY (mandatory):\n" . implode("\n", $lines);
      }

      // ── Cache-key salting ────────────────────────────────────────────────────

      /**
       * LLM cache keys are salted with the glossary, prompt override, and
       * review setting so changing any of them invalidates stale translations.
       */
      protected function getCacheKey(string $text, string $target, string $source): string
      {
            return parent::getCacheKey($text, $target, $source) . ':' . $this->promptFingerprint();
      }

      protected function promptFingerprint(): string
      {
            return $this->promptFingerprint ??= substr(hash('sha256', json_encode([
                  config('lara-glot.glossary', []),
                  config('lara-glot.prompt'),
                  config('lara-glot.review.enabled', false),
            ])), 0, 12);
      }
}
