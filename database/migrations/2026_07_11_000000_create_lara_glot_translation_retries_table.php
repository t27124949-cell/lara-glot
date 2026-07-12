<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dead-letter / retry state for translation units that fell back to source
 * text. One row per (type, target, item_key, locale):
 *
 *  - type 'file':  target = file base name,   item_key = dot-notation key
 *  - type 'model': target = model class name, item_key = "{id}:{attribute}"
 *
 * status: pending   → will be re-attempted by laraglot:retry
 *         resolved  → translated successfully (or became stale) — kept for audit trail
 *         exhausted → attempt cap reached; surfaced for human review
 */
return new class extends Migration
{
      public function up(): void
      {
            Schema::create('lara_glot_translation_retries', function (Blueprint $table) {
                  $table->id();
                  $table->string('type', 8);
                  $table->string('target');
                  $table->string('item_key');
                  $table->string('locale', 12);
                  $table->string('source_hash', 64);
                  $table->unsignedTinyInteger('attempts')->default(0);
                  $table->string('status', 12)->default('pending')->index();
                  $table->text('last_error')->nullable();
                  $table->timestamp('next_retry_at')->nullable()->index();
                  $table->timestamps();

                  $table->unique(
                        ['type', 'target', 'item_key', 'locale'],
                        'lara_glot_retries_unit_unique'
                  );
            });
      }

      public function down(): void
      {
            Schema::dropIfExists('lara_glot_translation_retries');
      }
};
