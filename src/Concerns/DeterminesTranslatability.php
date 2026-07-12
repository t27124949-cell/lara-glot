<?php

namespace Tonydev\LaraGlot\Concerns;

/**
 * Shared rules for deciding whether a key/value pair is eligible for
 * translation. Used by file translation and by the audit/repair command so
 * both always agree on what counts as translatable.
 */
trait DeterminesTranslatability
{
      /**
       * True when the key's last segment is listed in lara-glot.ignored_keys.
       */
      protected function isIgnoredKey(string|int $key): bool
      {
            $lastSegment = last(explode('.', (string) $key));

            return in_array($lastSegment, config('lara-glot.ignored_keys', []), true);
      }

      /**
       * True when the VALUE itself must never be translated — references like
       * 'route:contact', bare URLs, or mailto:/tel: links, matched against the
       * regex list in lara-glot.protected_value_patterns.
       */
      protected function isProtectedValue(mixed $value): bool
      {
            if (!is_string($value)) {
                  return false;
            }

            foreach ((array) config('lara-glot.protected_value_patterns', []) as $pattern) {
                  if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $value) === 1) {
                        return true;
                  }
            }

            return false;
      }

      /**
       * True when the value is expected to stay identical across locales
       * (brand names, "OK", "SMS", numeric labels) per lara-glot.audit.allowlist.
       * Such values are never flagged or re-translated by the audit.
       */
      protected function isAuditAllowlisted(mixed $value): bool
      {
            return is_string($value)
                  && in_array($value, (array) config('lara-glot.audit.allowlist', []), true);
      }

      /**
       * True when a source value should be counted (and repaired) by the
       * audit: a real translatable string that is not exempt by any rule.
       */
      protected function isAuditable(string|int $key, mixed $value): bool
      {
            return is_string($value)
                  && trim($value) !== ''
                  && !$this->isIgnoredKey($key)
                  && !$this->isProtectedValue($value)
                  && !$this->isAuditAllowlisted($value);
      }
}
