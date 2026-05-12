<?php

namespace Tonydev\LaraGlot\Drivers\Concerns;

/**
 * Protects dynamic tokens inside a translation string from being mangled
 * by translation APIs, then restores them afterward.
 *
 * Processing order is intentional and must not be changed:
 *  1. HTML <span translate="no"> elements — most specific; consume whole element first.
 *  2. Absolute URLs                       — must come BEFORE :word so that URL path
 *                                           segments like /:slug are not captured by rule 3.
 *  3. Laravel / generic :word variables   — last, now that URLs are already shielded.
 *
 * A single shared counter ensures placeholder keys are always unique within
 * one protect/restore cycle regardless of how many types appear.
 */
trait ProtectsPlaceholders
{
      /**
       * Replace dynamic tokens with opaque placeholder keys.
       *
       * @param  string $text  Original translation string.
       * @return array{0: string, 1: array<string, string>}
       *         [protected text, map of placeholder-key => original value]
       */
      protected function protectPlaceholders(string $text): array
      {
            $placeholders = [];
            $i = 0;

            // ── 1. HTML no-translate spans ────────────────────────────────────────
            $text = preg_replace_callback(
                  '/<span\s[^>]*translate=["\']no["\'][^>]*>.*?<\/span>/is',
                  static function (array $m) use (&$placeholders, &$i): string {
                        $key = "__HTML_{$i}__";
                        $placeholders[$key] = $m[0];
                        $i++;
                        return $key;
                  },
                  $text
            );

            // ── 2. Absolute URLs (http / https) ───────────────────────────────────
            //    Stops at whitespace or common string-ending punctuation that is
            //    unlikely to be part of a URL in a translation file.
            $text = preg_replace_callback(
                  '/https?:\/\/[^\s<>"\'`\[\]{}\\\]+/i',
                  static function (array $m) use (&$placeholders, &$i): string {
                        $key = "__URL_{$i}__";
                        $placeholders[$key] = $m[0];
                        $i++;
                        return $key;
                  },
                  $text
            );

            // ── 3. Laravel / named :word placeholders ─────────────────────────────
            //    Negative lookbehind prevents matching word characters that happen
            //    to precede a colon (e.g. "label:" in plain prose).
            $text = preg_replace_callback(
                  '/(?<!\w):\w+/u',
                  static function (array $m) use (&$placeholders, &$i): string {
                        $key = "__VAR_{$i}__";
                        $placeholders[$key] = $m[0];
                        $i++;
                        return $key;
                  },
                  $text
            );

            return [$text, $placeholders];
      }

      /**
       * Swap every placeholder token back to its original value.
       *
       * @param  string               $text
       * @param  array<string,string> $placeholders  Map returned by protectPlaceholders().
       * @return string
       */
      protected function restorePlaceholders(string $text, array $placeholders): string
      {
            if (empty($placeholders)) {
                  return $text;
            }

            return str_replace(
                  array_keys($placeholders),
                  array_values($placeholders),
                  $text
            );
      }
}
