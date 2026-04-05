This is the updated, full **README.md**. I've integrated the new **Filament Actions**, the **CLI Progress Bar** details, and the **Logging/Troubleshooting** section to make it a complete professional guide.

---

# LaraGlot 🌍
**Smart Auto-Translation for Laravel Models**

LaraGlot is a professional-grade Laravel package designed to handle automated, background translations for Eloquent models. It supports complex JSON structures (like Filament Page Builders), HTML content chunking, and SEO auto-population using the Google Translate API.

---

## 🚀 Features

- **Automatic Translation:** Hooks into Eloquent `saved` events seamlessly.
- **Smart JSON Handling:** Recursively translates nested arrays while skipping non-translatable keys (URLs, IDs, Slugs).
- **HTML Chunking:** Automatically splits large text blocks (over 2000 chars) to avoid API limits and timeouts.
- **SEO Auto-Pilot:** Automatically populates `meta_title` and `meta_description` from your main content if they are empty.
- **Filament Ready:** Includes ready-to-use Actions and Bulk Actions for your Filament resources.
- **CLI Progress Bar:** Real-time feedback during bulk database synchronization.
- **Queue Ready:** Dispatches jobs to background workers with built-in exponential backoff for API reliability.

---

## 📦 Installation

You can install the package via composer:

```bash
composer require tonydev/lara-glot
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=lara-glot-config
```

---

## ⚙️ Configuration

In `config/lara-glot.php`, define your source language, target languages, and supported models.

```php
return [
    'source_locale' => 'en',
    'languages' => [
        'es' => 'Spanish',
        'fr' => 'French',
        'ar' => 'Arabic',
        // Add more as needed...
    ],
    'queue' => 'translations',
    'models' => [
        \App\Models\Post::class,
    ],
];
```

---

## 🛠 Usage

### 1. Prepare your Model
Your model should use the `HasSmartTranslations` trait. It works perfectly alongside `spatie/laravel-translatable`.

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Tonydev\LaraGlot\Traits\HasSmartTranslations;
use Spatie\Translatable\HasTranslations;

class Post extends Model
{
    use HasTranslations, HasSmartTranslations;

    public $translatable = ['title', 'body', 'meta_title', 'meta_description'];

    public function getTranslatableAttributes(): array
    {
        return $this->translatable;
    }
}
```

### 2. Bulk Syncing (CLI)
To translate existing records or force an update across your database with a visual progress bar:

```bash
# Sync all models defined in config
php artisan laraglot:sync

# Force re-translation of a specific model
php artisan laraglot:sync "App\Models\Post" --force
```

### 3. Filament Integration
Add manual translation triggers to your Filament Resource tables:

```php
use Filament\Tables;
use Filament\Notifications\Notification;
use Tonydev\LaraGlot\Jobs\TranslateModelJob;

// Inside Table Actions
Tables\Actions\Action::make('translate')
    ->label('Translate Now')
    ->icon('heroicon-o-language')
    ->requiresConfirmation()
    ->action(function ($record) {
        TranslateModelJob::dispatch(get_class($record), $record->getKey(), true)
            ->onQueue(config('lara-glot.queue'));

        Notification::make()->title('Translation Started')->success()->send();
    }),

// Inside Bulk Actions
Tables\Actions\BulkAction::make('translate_selected')
    ->label('Translate Selected')
    ->icon('heroicon-o-language')
    ->action(function (\Illuminate\Support\Collection $records) {
        $records->each(fn($record) => TranslateModelJob::dispatch(get_class($record), $record->getKey(), true));
        Notification::make()->title('Bulk Translation Started')->success()->send();
    })
```

---

## 🏗 Architecture

- **TranslationService:** Wrapper for the Google Translate API with multi-layer caching.
- **SmartTranslationService:** The logic engine for recursive array and HTML processing.
- **TranslateModelJob:** Queueable worker with a 10-minute timeout and 3-step incremental backoff.
- **HasSmartTranslations:** The trait that connects your models to the engine.

---

## 📋 Troubleshooting & Logs

Monitor progress in `storage/logs/laravel.log`:

* `🚀 [LaraGlot] Translation Job Started` — Job picked up by worker.
* `🌍 [LaraGlot] Translating [es]: ...` — Specific string being sent to API.
* `✅ [LaraGlot] Translation completed` — Success!
* `❌ [LaraGlot] Translation Job Failed` — Errors (API limits, network) are logged here before retrying.

---

## 📄 License
The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

**Developed by Tonydev**