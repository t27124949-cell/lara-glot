<?php

namespace Tonydev\LaraGlot\Drivers\Concerns;

/**
 * Protects dynamic tokens inside a translation string from being mangled
 * by translation APIs, then restores them afterward.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Why this trait exists
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Translation APIs (Google, DeepL, LLMs, etc.) do not know that `:name`,
 * `https://example.com`, or a `<span translate="no">` block should be left
 * verbatim. Without protection they will:
 *  - Translate ":count" into ":nombre" (French) or "：计数" (Chinese).
 *  - Break URL path segments that look like words.
 *  - Strip or rewrite HTML attributes.
 *
 * The protect → translate → restore cycle avoids all of this:
 *  1. Replace each sensitive token with an opaque key  (__VAR_0__, __URL_1__).
 *  2. Send the sanitised string to the translation API.
 *  3. Swap the opaque keys back to their originals in the response.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Processing order inside protectPlaceholders()  — do not change it
 * ──────────────────────────────────────────────────────────────────────────────
 *
 *  1. HTML <span translate="no"> elements
 *     Most specific. Consuming the entire element first prevents its inner
 *     content (which may contain URLs or :word tokens) from being captured
 *     by the later rules.
 *
 *  2. Absolute URLs  (http:// and https://)
 *     Must run BEFORE the :word rule. A URL like "https://example.com/:slug"
 *     contains "/:slug"; if :word ran first it would capture ":slug" and then
 *     the URL rule would be left with a broken "https://example.com/__VAR_0__".
 *
 *  3. Laravel / generic :word placeholders
 *     Safest to run last — by this point URLs and HTML spans are already
 *     replaced with opaque tokens, so there is no risk of double-substitution.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Counter design
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * A single integer counter $i is shared across all three regex callbacks.
 * This guarantees every placeholder key is unique within one protect/restore
 * cycle even if a string contains a mix of types:
 *
 *   "Hello :name, see https://example.com for <span translate='no'>details</span>"
 *   →
 *   __HTML_0__ is consumed first (span), __URL_1__ is the URL, __VAR_2__ is :name
 *   (order reflects regex execution order, not their position in the string)
 */
trait ProtectsPlaceholders
{
      /**
       * Replace sensitive tokens with opaque placeholder keys.
       *
       * Returns a two-element tuple:
       *  [0] string  The sanitised text, safe to send to a translation API.
       *  [1] array   A map of placeholder-key → original value, used by
       *              restorePlaceholders() to undo the substitution.
       *
       * @param  string $text  The original translation string.
       * @return array{0: string, 1: array<string, string>}
       */
      protected function protectPlaceholders(string $text): array
      {
            // $placeholders accumulates every substitution we make.
            // It is passed by reference into each regex callback so the map
            // is built incrementally across all three passes.
            $placeholders = [];

            // $i is the shared counter — passed by reference so it increments
            // continuously across all three callbacks (not reset per-type).
            $i = 0;

            // ── Pass 1: HTML <span translate="no"> elements ───────────────────────
            //
            // Pattern breakdown:
            //   <span\s          opening tag with at least one whitespace char
            //   [^>]*            any attributes before translate="no"
            //   translate=["\']no["\']  the attribute in either quote style
            //   [^>]*>           remaining attributes and closing >
            //   .*?              the inner content (non-greedy)
            //   <\/span>         the closing tag
            //   /is              case-insensitive (translate="NO"), dot-matches-newline
            //
            $text = preg_replace_callback(
                  '/<span\s[^>]*translate=["\']no["\'][^>]*>.*?<\/span>/is',
                  static function (array $m) use (&$placeholders, &$i): string {
                        $key = "__HTML_{$i}__";
                        $placeholders[$key] = $m[0]; // store the full matched element
                        $i++;
                        return $key;
                  },
                  $text
            );

            // ── Pass 2: Absolute URLs (http / https) ──────────────────────────────
            //
            // Pattern breakdown:
            //   https?:\/\/      the scheme — http:// or https://
            //   [^\s<>"'`\[\]{}\\\]+
            //                    everything that is NOT whitespace or a character
            //                    that would end a URL in typical prose / HTML
            //   /i               case-insensitive (HTTP://, HTTPS://)
            //
            // The character class deliberately excludes common "string-terminating"
            // punctuation (quotes, angle brackets, backticks, square brackets, braces,
            // backslash) so we don't swallow surrounding template syntax.
            //
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

            // ── Pass 3: Laravel / generic :word placeholders ──────────────────────
            //
            // Pattern breakdown:
            //   (?<!\w)          negative lookbehind — do NOT match if the character
            //                    immediately before the colon is a word character.
            //                    This prevents matching "label:" in plain prose while
            //                    still matching ":name" at word boundaries.
            //   :\w+             a colon followed by one or more word characters
            //                    (letters, digits, underscores).
            //   /u               Unicode mode — \w covers non-ASCII word chars correctly.
            //
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
       * Swap every opaque placeholder key back to its original value.
       *
       * Uses a single str_replace() call (array form) which is faster than
       * iterating and calling str_replace() once per key — the PHP engine can
       * scan the string in a single pass.
       *
       * @param  string               $text          Text returned by the translation API.
       * @param  array<string,string> $placeholders  The map produced by protectPlaceholders().
       * @return string                              Fully restored translation string.
       */
      protected function restorePlaceholders(string $text, array $placeholders): string
      {
            // Nothing to restore — skip the function-call overhead.
            if (empty($placeholders)) {
                  return $text;
            }

            // str_replace() with array arguments replaces all keys with their
            // corresponding values in a single pass over the string.
            return str_replace(
                  array_keys($placeholders),   // search:  __HTML_0__, __URL_1__, __VAR_2__
                  array_values($placeholders), // replace: original tokens
                  $text
            );
      }
}
