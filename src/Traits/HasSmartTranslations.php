<?php

namespace Tonydev\LaraGlot\Traits;

// FIXED: Import the Job from the package namespace
use Tonydev\LaraGlot\Jobs\TranslateModelJob;
use Illuminate\Support\Facades\Log;

trait HasSmartTranslations
{
      /**
       * Prevents infinite loops when the Job saves the model.
       */
      public bool $skipTranslation = false;

      /**
       * Boot the trait and register the saved observer.
       */
      public static function bootHasSmartTranslations(): void
      {
            static::saved(function ($model) {
                  // 1. Check for the skip flag (set by the Service/Job)
                  if ($model->skipTranslation) {
                        return;
                  }

                  // 2. Determine which fields to watch
                  $translatableFields = method_exists($model, 'getTranslatableAttributes')
                        ? $model->getTranslatableAttributes()
                        : ['content', 'title', 'meta_title'];

                  // 3. Only dispatch if relevant data changed or record is brand new
                  if (!$model->wasChanged($translatableFields) && !$model->wasRecentlyCreated) {
                        return;
                  }

                  // 4. Secure Dispatch
                  TranslateModelJob::dispatch(
                        get_class($model),
                        $model->getKey(),
                        false // $force = false
                  )
                        ->onQueue(config('lara-glot.queue', 'translations')) // FIXED: Use package config
                        ->afterCommit();

                  Log::info("🚀 [LaraGlot] Auto-dispatched translation for " . get_class($model) . " ID: " . $model->getKey());
            });
      }
}