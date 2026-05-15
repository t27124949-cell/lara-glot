<?php

namespace Tonydev\LaraGlot\Traits;

/**
 * Marks a model as supporting LaraGlot translations.
 * 
 * This trait is purely structural. It signals to LaraGlot that this model
 * uses Spatie's HasTranslations and is eligible for translation via
 * the ModelTranslationManager service.
 * 
 * Translation is EXPLICIT — use ModelTranslationManager::translateAsync()
 * or translateSync() to trigger it. No automatic dispatch on save.
 * 
 * WHY NOT AUTO-DISPATCH?
 * - Runtime flags don't survive queue serialization
 * - Saved events fire multiple times, causing loops
 * - Better to be explicit: the caller controls when to translate
 * - Easier to debug and reason about
 */
trait HasSmartTranslations
{
      // No logic here. This trait is just a marker.
      // Use ModelTranslationManager in your code to translate.
}