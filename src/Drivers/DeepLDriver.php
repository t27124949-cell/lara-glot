<?php

namespace Tonydev\LaraGlot\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Translation driver backed by the DeepL REST API.
 *
 * @see https://www.deepl.com/docs-api/translate-text/
 */
class DeepLDriver extends AbstractChunkableDriver
{
      protected string $apiKey;
      protected string $baseUrl;

      public function __construct()
      {
            $this->apiKey = (string) config(
                  'lara-glot.drivers.deepl.api_key',
                  env('DEEPL_API_KEY', '')
            );

            // Free-tier keys end with ':fx' and use a different subdomain.
            $this->baseUrl = (string) config(
                  'lara-glot.drivers.deepl.base_url',
                  str_ends_with($this->apiKey, ':fx')
                  ? 'https://api-free.deepl.com/v2'
                  : 'https://api.deepl.com/v2'
            );

            $this->chunkSize = (int) config('lara-glot.drivers.deepl.chunk_size', 50);
            $this->maxRetries = (int) config('lara-glot.drivers.deepl.max_retries', 3);
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Core chunk translation
      // ─────────────────────────────────────────────────────────────────────────

      protected function translateChunk(array $chunk, string $target, string $source): array
      {
            $deepLTarget = $this->normalizeLocale($target);
            $deepLSource = $this->normalizeLocale($source);
            $label = "LaraGlot:DeepL → [{$deepLTarget}]";

            Log::info("[{$label}] Sending chunk of " . count($chunk) . ' string(s).');

            try {
                  return $this->withRetry(
                        function () use ($chunk, $deepLTarget, $deepLSource, $label): array {

                              $response = Http::withHeaders([
                                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                                    'Content-Type' => 'application/json',
                              ])
                                    ->timeout(60)
                                    ->post("{$this->baseUrl}/translate", [
                                          'text' => array_values($chunk),
                                          'source_lang' => $deepLSource,
                                          'target_lang' => $deepLTarget,
                                          'tag_handling' => 'html',   // DeepL preserves HTML tags natively
                                          'split_sentences' => '1',      // split on punctuation and newlines
                                          'preserve_formatting' => true,
                                    ]);

                              if (!$response->successful()) {
                                    throw new \RuntimeException(
                                          "DeepL HTTP {$response->status()}: {$response->body()}"
                                    );
                              }

                              $translations = $response->json('translations', []);

                              if (!is_array($translations) || count($translations) !== count($chunk)) {
                                    throw new \RuntimeException(sprintf(
                                          'DeepL returned %d result(s) for %d input(s).',
                                          count((array) $translations),
                                          count($chunk)
                                    ));
                              }

                              $results = array_map(
                                    fn(array $t): string => $this->normalizeTranslated($t['text'] ?? ''),
                                    $translations
                              );

                              Log::info("[{$label}] Chunk translated successfully.");

                              return $results;
                        },
                        $this->maxRetries,
                        500,
                        ':DeepL'
                  );

            } catch (\Throwable $e) {
                  Log::error(
                        "[{$label}] Chunk failed permanently after {$this->maxRetries} attempt(s): {$e->getMessage()}"
                  );

                  return array_values($chunk); // graceful degradation: return originals
            }
      }

      // ─────────────────────────────────────────────────────────────────────────
      // Locale normalisation
      // ─────────────────────────────────────────────────────────────────────────

      /**
       * Map generic locale codes to DeepL's required regional variants.
       *
       * DeepL requires specific sub-codes for some languages; passing a bare
       * two-letter code results in an API error for those languages.
       *
       * @see https://www.deepl.com/docs-api/translate-text/
       */
      protected function normalizeLocale(string $locale): string
      {
            return match (strtoupper($locale)) {
                  'EN' => 'EN-US',   // British English available as EN-GB
                  'PT' => 'PT-PT',   // Brazilian Portuguese available as PT-BR
                  'ZH' => 'ZH-HANS', // Traditional Chinese available as ZH-HANT
                  default => strtoupper($locale),
            };
      }
}
