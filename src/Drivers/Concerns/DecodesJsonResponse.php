<?php

namespace Tonydev\LaraGlot\Drivers\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Robustly decodes a JSON array from a raw LLM response that may include:
 *  - Markdown code fences  (```json … ```)
 *  - Leading / trailing prose
 *  - A wrapper object      ({"translations": [...]})
 *  - A UTF-8 BOM
 *  - Windows-style CRLF line endings
 */
trait DecodesJsonResponse
{
      /**
       * Parse a raw model response into a plain PHP array.
       *
       * @param  string      $raw      Full string returned by the model.
       * @param  string      $context  Driver label used in log messages.
       * @return list<mixed>|null      Decoded array (re-indexed), or null on failure.
       */
      protected function decodeJsonResponse(string $raw, string $context = 'Driver'): ?array
      {
            $raw = trim($raw);

            // ── 1. Strip UTF-8 BOM ────────────────────────────────────────────────
            $raw = ltrim($raw, "\xEF\xBB\xBF");

            // ── 2. Strip markdown code fences ────────────────────────────────────
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```\s*$/i', '', $raw);
            $raw = trim($raw);

            // ── 3. Normalise line endings ─────────────────────────────────────────
            $raw = str_replace("\r\n", "\n", $raw);

            // ── 4. Extract first JSON structure from surrounding prose ────────────
            //    The 's' flag makes '.' match newlines so multiline arrays are caught.
            if (preg_match('/(\[.*\]|\{.*\})/su', $raw, $m)) {
                  $raw = $m[1];
            }

            // ── 5. Decode ─────────────────────────────────────────────────────────
            try {
                  $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException $e) {
                  Log::error("[LaraGlot:{$context}] JSON decode failed: {$e->getMessage()}", [
                        'raw' => mb_substr($raw, 0, 500),
                  ]);
                  return null;
            }

            // ── 6. Unwrap envelope objects: {"translations": [...]} etc. ──────────
            //    LLMs sometimes wrap the array in a single-key object despite instructions.
            while (is_array($decoded) && !array_is_list($decoded) && count($decoded) === 1) {
                  $decoded = reset($decoded);
            }

            if (!is_array($decoded)) {
                  Log::error("[LaraGlot:{$context}] Decoded value is not an array.", [
                        'type' => gettype($decoded),
                        'raw' => mb_substr($raw, 0, 500),
                  ]);
                  return null;
            }

            // Return a clean 0-indexed list
            return array_values($decoded);
      }
}
