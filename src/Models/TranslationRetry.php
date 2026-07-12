<?php

namespace Tonydev\LaraGlot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One translation unit that fell back to source text and is waiting to be
 * re-attempted (see RetryQueue and the laraglot:retry command).
 *
 * @property string $type          'file' | 'model'
 * @property string $target        File base name or model class
 * @property string $item_key      Dot key (file) or "{id}:{attribute}" (model)
 * @property string $locale
 * @property string $source_hash   sha256 of the source text at record time
 * @property int    $attempts
 * @property string $status        'pending' | 'resolved' | 'exhausted'
 * @property ?string $last_error
 * @property ?\Illuminate\Support\Carbon $next_retry_at
 */
class TranslationRetry extends Model
{
      public const STATUS_PENDING = 'pending';
      public const STATUS_RESOLVED = 'resolved';
      public const STATUS_EXHAUSTED = 'exhausted';

      protected $table = 'lara_glot_translation_retries';

      protected $guarded = [];

      protected $casts = [
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
      ];

      /** Units eligible for a retry right now. */
      public function scopeDue(Builder $query): Builder
      {
            return $query
                  ->where('status', self::STATUS_PENDING)
                  ->where(function (Builder $q) {
                        $q->whereNull('next_retry_at')
                              ->orWhere('next_retry_at', '<=', now());
                  });
      }
}
