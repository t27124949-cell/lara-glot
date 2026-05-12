<x-filament-panels::page>

    {{ $this->form }}

    {{--
    ╔═══════════════════════════════════════════════════════════════════════════╗
    ║  FILE ACTIONS                                                             ║
    ╚═══════════════════════════════════════════════════════════════════════════╝
    --}}
    <x-filament::section class="mt-2">
        <x-slot name="heading">File Actions</x-slot>
        <x-slot name="description">
            Fill in the <strong>File Translation</strong> section above, then dispatch or preview.
        </x-slot>

        <div class="flex flex-wrap items-center gap-3">

            {{-- Batch-dispatch all selected files × locales --}}
            <x-filament::button color="warning" icon="heroicon-m-queue-list" wire:click="dispatchFileJobs"
                wire:loading.attr="disabled" wire:target="dispatchFileJobs">
                <span wire:loading.remove wire:target="dispatchFileJobs">Dispatch File Job(s)</span>
                <span wire:loading wire:target="dispatchFileJobs">Queuing…</span>
            </x-filament::button>

            {{-- Preview first file × locale via a single background job --}}
            <x-filament::button color="primary" icon="heroicon-m-sparkles" wire:click="generatePreview"
                wire:loading.attr="disabled" wire:target="generatePreview" :disabled="$previewStatus === 'processing'">
                <span wire:loading.remove wire:target="generatePreview">
                    {{ $previewStatus === 'processing' ? 'Translating…' : 'Generate AI Preview' }}
                </span>
                <span wire:loading wire:target="generatePreview">Starting…</span>
            </x-filament::button>

        </div>

        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
            <strong>Dispatch</strong> queues all selected files × languages and shows live progress below.
            <strong>AI Preview</strong> translates the first file × language so you can review and edit before saving.
        </p>
    </x-filament::section>

    {{--
    ╔═══════════════════════════════════════════════════════════════════════════╗
    ║  FILE BATCH PROGRESS                                                      ║
    ║  wire:poll.3000ms only renders when $fileBatchId is set, so it stops     ║
    ║  polling the moment the batch finishes or is cancelled.                  ║
    ╚═══════════════════════════════════════════════════════════════════════════╝
    --}}
    @if ($fileBatchId || ($fileBatchProgress && $fileBatchProgress['finished']))
        <div @if ($fileBatchId) wire:poll.3000ms="pollFileBatch" @endif>
            <x-filament::section class="mt-2">

                <x-slot name="heading">
                    @if ($fileBatchProgress['finished'] ?? false)
                        @if (($fileBatchProgress['failed'] ?? 0) > 0)
                            ⚠️ File Batch Complete (with errors)
                        @else
                            ✅ File Batch Complete
                        @endif
                    @else
                        ⏳ File Batch Running
                    @endif
                </x-slot>

                @if ($fileBatchProgress)
                    @php
                        $fp = $fileBatchProgress;
                        $barColor =
                            $fp['failed'] > 0
                                ? 'bg-warning-500'
                                : ($fp['finished']
                                    ? 'bg-success-500'
                                    : 'bg-primary-500');
                    @endphp

                    <div class="space-y-3">

                        {{-- Label --}}
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ $fp['label'] }}
                        </p>

                        {{-- Progress bar --}}
                        <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-full rounded-full transition-all duration-500 {{ $barColor }}"
                                style="width: {{ $fp['progress'] }}%"></div>
                        </div>

                        {{-- Stats row --}}
                        <div class="flex flex-wrap gap-4 text-sm">
                            <span class="text-gray-500 dark:text-gray-400">
                                <strong class="text-gray-700 dark:text-gray-200">{{ $fp['progress'] }}%</strong>
                                complete
                            </span>
                            <span class="text-success-600 dark:text-success-400">
                                ✅ {{ $fp['processed'] }} done
                            </span>
                            @if ($fp['pending'] > 0)
                                <span class="text-gray-500">
                                    ⏳ {{ $fp['pending'] }} pending
                                </span>
                            @endif
                            @if ($fp['failed'] > 0)
                                <span class="text-danger-600 dark:text-danger-400">
                                    ❌ {{ $fp['failed'] }} failed
                                </span>
                            @endif
                            <span class="text-gray-400">
                                of {{ $fp['total'] }} total
                            </span>
                        </div>

                        {{-- Cancel button (only while running) --}}
                        @if (!($fp['finished'] ?? false) && !($fp['cancelled'] ?? false) && $fileBatchId)
                            <div class="pt-1">
                                <x-filament::button color="gray" size="sm" wire:click="cancelBatch('file')"
                                    wire:confirm="Cancel this batch? Jobs already running will finish, but queued jobs will be skipped.">
                                    Cancel Batch
                                </x-filament::button>
                            </div>
                        @endif

                        @if ($fp['failed'] > 0)
                            <p class="text-xs text-warning-600 dark:text-warning-400">
                                Failed jobs can be retried with <code class="font-mono">php artisan queue:retry
                                    all</code>
                            </p>
                        @endif

                    </div>
                @endif

            </x-filament::section>
        </div>
    @endif

    {{--
    ╔═══════════════════════════════════════════════════════════════════════════╗
    ║  MODEL ACTIONS                                                            ║
    ╚═══════════════════════════════════════════════════════════════════════════╝
    --}}
    <x-filament::section class="mt-2">
        <x-slot name="heading">Model Actions</x-slot>
        <x-slot name="description">
            Fill in the <strong>Model Translation</strong> section above, then dispatch jobs.
        </x-slot>

        <div class="flex flex-wrap items-center gap-3">

            <x-filament::button color="info" icon="heroicon-m-circle-stack" wire:click="dispatchModelJobs"
                wire:loading.attr="disabled" wire:target="dispatchModelJobs">
                <span wire:loading.remove wire:target="dispatchModelJobs">Dispatch Model Job(s)</span>
                <span wire:loading wire:target="dispatchModelJobs">Queuing…</span>
            </x-filament::button>

        </div>

        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
            Each record is queued individually. Large tables are chunked in groups of 100 to protect memory.
            Jobs run on the <code class="font-mono">[{{ config('lara-glot.queue', 'translations') }}]</code> queue.
        </p>
    </x-filament::section>

    {{--
    ╔═══════════════════════════════════════════════════════════════════════════╗
    ║  MODEL BATCH PROGRESS                                                     ║
    ╚═══════════════════════════════════════════════════════════════════════════╝
    --}}
    @if ($modelBatchId || ($modelBatchProgress && $modelBatchProgress['finished']))
        <div @if ($modelBatchId) wire:poll.3000ms="pollModelBatch" @endif>
            <x-filament::section class="mt-2">

                <x-slot name="heading">
                    @if ($modelBatchProgress['finished'] ?? false)
                        @if (($modelBatchProgress['failed'] ?? 0) > 0)
                            ⚠️ Model Batch Complete (with errors)
                        @else
                            ✅ Model Batch Complete
                        @endif
                    @else
                        ⏳ Model Batch Running
                    @endif
                </x-slot>

                @if ($modelBatchProgress)
                    @php
                        $mp = $modelBatchProgress;
                        $barColor =
                            $mp['failed'] > 0 ? 'bg-warning-500' : ($mp['finished'] ? 'bg-success-500' : 'bg-info-500');
                    @endphp

                    <div class="space-y-3">

                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ $mp['label'] }}
                        </p>

                        <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-full rounded-full transition-all duration-500 {{ $barColor }}"
                                style="width: {{ $mp['progress'] }}%"></div>
                        </div>

                        <div class="flex flex-wrap gap-4 text-sm">
                            <span class="text-gray-500 dark:text-gray-400">
                                <strong class="text-gray-700 dark:text-gray-200">{{ $mp['progress'] }}%</strong>
                                complete
                            </span>
                            <span class="text-success-600 dark:text-success-400">
                                ✅ {{ $mp['processed'] }} done
                            </span>
                            @if ($mp['pending'] > 0)
                                <span class="text-gray-500">
                                    ⏳ {{ $mp['pending'] }} pending
                                </span>
                            @endif
                            @if ($mp['failed'] > 0)
                                <span class="text-danger-600 dark:text-danger-400">
                                    ❌ {{ $mp['failed'] }} failed
                                </span>
                            @endif
                            <span class="text-gray-400">
                                of {{ $mp['total'] }} total
                            </span>
                        </div>

                        @if (!($mp['finished'] ?? false) && !($mp['cancelled'] ?? false) && $modelBatchId)
                            <div class="pt-1">
                                <x-filament::button color="gray" size="sm" wire:click="cancelBatch('model')"
                                    wire:confirm="Cancel this batch? Jobs already running will finish, but queued jobs will be skipped.">
                                    Cancel Batch
                                </x-filament::button>
                            </div>
                        @endif

                        @if ($mp['failed'] > 0)
                            <p class="text-xs text-warning-600 dark:text-warning-400">
                                Failed jobs can be retried with <code class="font-mono">php artisan queue:retry
                                    all</code>
                            </p>
                        @endif

                    </div>
                @endif

            </x-filament::section>
        </div>
    @endif

    {{--
    ╔═══════════════════════════════════════════════════════════════════════════╗
    ║  PREVIEW — PROCESSING SPINNER                                             ║
    ║  Separate from batch progress — this is for the single-file preview job. ║
    ╚═══════════════════════════════════════════════════════════════════════════╝
    --}}
    @if ($previewStatus === 'processing')
        <div wire:poll.3000ms="checkPreviewStatus">
            <x-filament::section class="mt-4">
                <div class="flex items-center gap-4 py-1">
                    <x-filament::loading-indicator class="h-8 w-8 text-primary-500" />
                    <div>
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                            Generating preview:
                            <span class="font-mono">{{ $previewFile }}.php</span>
                            &rarr;
                            <span class="font-bold uppercase">{{ $previewLocale }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            Running on the queue worker. This page updates automatically.
                        </p>
                    </div>
                </div>
            </x-filament::section>
        </div>
    @endif

    {{--
    ╔═══════════════════════════════════════════════════════════════════════════╗
    ║  PREVIEW — ERROR BANNER                                                   ║
    ╚═══════════════════════════════════════════════════════════════════════════╝
    --}}
    @if ($previewStatus === 'failed' && $previewError)
        <x-filament::section class="mt-4">
            <div class="flex items-start gap-3 rounded-lg bg-danger-50 p-4 dark:bg-danger-900/20">
                <x-heroicon-o-x-circle class="mt-0.5 h-5 w-5 shrink-0 text-danger-500" />
                <div class="flex-1">
                    <p class="text-sm font-semibold text-danger-700 dark:text-danger-400">Preview failed</p>
                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-300">{{ $previewError }}</p>
                    <button wire:click="resetPreview"
                        class="mt-2 text-xs text-danger-500 underline hover:text-danger-700">
                        Dismiss
                    </button>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{--
    ╔═══════════════════════════════════════════════════════════════════════════╗
    ║  PREVIEW — REVIEW TABLE                                                   ║
    ╚═══════════════════════════════════════════════════════════════════════════╝
    --}}
    @if ($previewStatus === 'done' && !empty($editedTranslations))
        <x-filament::section class="mt-4">

            <x-slot name="heading">
                Review:
                <span class="font-mono font-normal">{{ $previewFile }}.php</span>
                &rarr;
                <span class="font-bold uppercase text-primary-600 dark:text-primary-400">{{ $previewLocale }}</span>
            </x-slot>

            <x-slot name="description">
                Edit any translation inline. Changes are not written to disk until you click Save.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 text-left dark:divide-white/5">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th
                                class="w-1/2 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                English (source)
                            </th>
                            <th
                                class="w-1/2 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ strtoupper($previewLocale) }}
                                <span class="normal-case font-normal">(editable)</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($originalData as $key => $value)
                            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-4 py-3 align-top text-sm">
                                    <span
                                        class="mb-1 block font-mono text-xs text-gray-400">{{ $key }}</span>
                                    <span class="text-gray-700 dark:text-gray-200">{{ $value }}</span>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="text"
                                            wire:model.live="editedTranslations.{{ $key }}" />
                                    </x-filament::input.wrapper>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-slot name="footer">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-400">{{ count($editedTranslations) }} key(s)</span>
                    <div class="flex gap-3">
                        <x-filament::button color="gray" wire:click="resetPreview">
                            Cancel
                        </x-filament::button>
                        <x-filament::button color="success" icon="heroicon-m-arrow-down-tray"
                            wire:click="savePreview" wire:loading.attr="disabled" wire:target="savePreview">
                            <span wire:loading.remove wire:target="savePreview">
                                Save lang/{{ $previewLocale }}/{{ $previewFile }}.php
                            </span>
                            <span wire:loading wire:target="savePreview">Saving…</span>
                        </x-filament::button>
                    </div>
                </div>
            </x-slot>

        </x-filament::section>
    @endif

</x-filament-panels::page>
