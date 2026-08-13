<x-filament-panels::page>
    <div
        class="space-y-6"
        x-data
        x-on:crm-portability-updated.window="requestAnimationFrame(() => document.querySelector('[data-portability-step][aria-current=step]')?.focus())"
    >
        <div class="sr-only" aria-live="polite" aria-atomic="true">
            Current import step: {{ str($step)->headline() }}.
            @if ($import)
                Import status: {{ str($import['status'])->headline() }}.
            @endif
        </div>

        @unless ($backendReady)
            <x-filament::section>
                <div role="status" class="flex items-start gap-3 text-sm text-gray-600 dark:text-gray-300">
                    <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="mt-0.5 size-5 shrink-0" />
                    <div>
                        <p class="font-semibold text-gray-950 dark:text-white">Data portability is being connected</p>
                        <p>The protected import service is not available yet. No file can be selected or stored until it is ready.</p>
                    </div>
                </div>
            </x-filament::section>
        @endunless

        <nav aria-label="CRM import progress">
            @php
                $steps = [
                    'prepare' => ['Prepare', 'Template and upload'],
                    'map' => ['Map', 'Match CSV columns'],
                    'review' => ['Review', 'Dry run and duplicates'],
                    'commit' => ['Commit', 'Apply reviewed rows'],
                    'outcome' => ['Outcome', 'Results and retry'],
                ];
                $stepKeys = array_keys($steps);
                $activeIndex = array_search($step, $stepKeys, true);
            @endphp
            <ol class="maestro-portability-stepper">
                @foreach ($steps as $key => [$label, $description])
                    @php $stepIndex = array_search($key, $stepKeys, true); @endphp
                    <li @class(['is-complete' => $stepIndex < $activeIndex, 'is-current' => $key === $step])>
                        <div
                            data-portability-step
                            @if ($key === $step) aria-current="step" tabindex="-1" @endif
                            class="maestro-portability-step"
                        >
                            <span class="maestro-portability-step-number" aria-hidden="true">
                                {{ $stepIndex < $activeIndex ? '✓' : $stepIndex + 1 }}
                            </span>
                            <span>
                                <strong>{{ $label }}</strong>
                                <small>{{ $description }}</small>
                            </span>
                        </div>
                    </li>
                @endforeach
            </ol>
        </nav>

        @if ($step === 'prepare')
            <div class="grid gap-6 xl:grid-cols-3">
                <x-filament::section class="xl:col-span-2">
                    <x-slot name="heading">Start with a clean template</x-slot>
                    <x-slot name="description">A guided CSV flow catches mistakes before any person or household changes.</x-slot>

                    <div class="maestro-portability-callout">
                        <div>
                            <p class="font-semibold text-gray-950 dark:text-white">Recommended order</p>
                            <ol class="mt-2 list-inside list-decimal space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                <li>Download the current template.</li>
                                <li>Keep one person per row and leave unknown values blank.</li>
                                <li>Upload, map, dry-run, and resolve every possible duplicate.</li>
                            </ol>
                        </div>
                        @if ($templateUrl)
                            <x-filament::button tag="a" :href="$templateUrl" icon="heroicon-o-document-arrow-down" color="gray">
                                Download template
                            </x-filament::button>
                        @endif
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">Safe by default</x-slot>
                    <ul class="space-y-3 text-sm text-gray-600 dark:text-gray-300">
                        <li>Private staging with tenant and actor reauthorization.</li>
                        <li>No database writes during mapping or dry run.</li>
                        <li>No passwords, tokens, private paths, or raw formulas in reports.</li>
                    </ul>
                </x-filament::section>
            </div>
        @endif

        @if ($import)
            <x-filament::section>
                <x-slot name="heading">{{ $import['fileName'] }}</x-slot>
                <x-slot name="description">Import {{ substr($import['id'], -8) }} · {{ str($import['status'])->headline() }}</x-slot>
                <x-slot name="afterHeader">
                    <x-filament::button wire:click="resetImport" color="gray" size="sm">Start over</x-filament::button>
                </x-slot>

                @if ($step === 'map')
                    <form wire:submit="previewImport" class="space-y-5" aria-describedby="mapping-help">
                        <p id="mapping-help" class="text-sm text-gray-600 dark:text-gray-300">
                            Match each detected CSV heading to a Maestro field. Leave columns you do not need as “Do not import.”
                        </p>
                        <div class="maestro-portability-mapping">
                            @foreach ($import['sourceColumns'] as $source)
                                @php $inputId = 'mapping-'.substr(hash('sha256', $source), 0, 12); @endphp
                                <div class="maestro-portability-mapping-row">
                                    <label for="{{ $inputId }}">
                                        <span class="font-semibold text-gray-950 dark:text-white">{{ $source }}</span>
                                        <small>CSV column</small>
                                    </label>
                                    <span aria-hidden="true">→</span>
                                    <select
                                        id="{{ $inputId }}"
                                        wire:change="setMapping({{ Illuminate\Support\Js::from($source) }}, $event.target.value)"
                                        class="fi-select-input"
                                    >
                                        <option value="">Do not import</option>
                                        @foreach ($import['mappingTargets'] as $value => $label)
                                            <option value="{{ $value }}" @selected(($mapping[$source] ?? null) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                        @error('mapping') <p role="alert" class="text-sm text-danger-600">{{ $message }}</p> @enderror
                        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled" wire:target="previewImport">
                            Run dry review
                        </x-filament::button>
                    </form>
                @endif

                @if ($step === 'review')
                    <div class="space-y-6">
                        <div class="maestro-portability-summary" aria-label="Dry-run summary">
                            @foreach ([
                                'total' => 'Rows read',
                                'valid' => 'Valid rows',
                                'invalid' => 'Needs correction',
                                'duplicates' => 'Possible duplicates',
                                'creates' => 'Would create',
                                'updates' => 'Would update',
                                'skips' => 'Would skip',
                            ] as $key => $label)
                                <div><strong>{{ number_format($import['summary'][$key] ?? 0) }}</strong><span>{{ $label }}</span></div>
                            @endforeach
                        </div>

                        @if ($import['duplicates'])
                            <div class="space-y-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 class="font-semibold text-gray-950 dark:text-white">Resolve possible duplicates</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-300">Every row needs an explicit decision. Update never silently replaces a record.</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2" aria-label="Apply one resolution to every duplicate">
                                        <x-filament::button wire:click="applyDuplicateResolution('update')" color="gray" size="sm">Update all</x-filament::button>
                                        <x-filament::button wire:click="applyDuplicateResolution('create')" color="gray" size="sm">Create all</x-filament::button>
                                        <x-filament::button wire:click="applyDuplicateResolution('skip')" color="gray" size="sm">Skip all</x-filament::button>
                                    </div>
                                </div>

                                <div class="maestro-portability-duplicates">
                                    @foreach ($import['duplicates'] as $duplicate)
                                        <fieldset>
                                            <legend>
                                                Row {{ $duplicate['row'] }} · {{ $duplicate['label'] }}
                                                @if ($duplicate['matched_to'])
                                                    <small>Possible match: {{ $duplicate['matched_to'] }}</small>
                                                @endif
                                            </legend>
                                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ implode(' · ', $duplicate['reasons']) }}</p>
                                            <div class="mt-3 flex flex-wrap gap-4">
                                                @foreach (['update' => 'Update match', 'create' => 'Create separate', 'skip' => 'Skip row'] as $value => $label)
                                                    <label class="inline-flex items-center gap-2">
                                                        <input
                                                            type="radio"
                                                            name="duplicate-{{ $duplicate['id'] }}"
                                                            value="{{ $value }}"
                                                            @checked(($duplicateResolutions[$duplicate['id']] ?? null) === $value)
                                                            wire:change="setDuplicateResolution({{ Illuminate\Support\Js::from((string) $duplicate['id']) }}, {{ Illuminate\Support\Js::from($value) }})"
                                                        >
                                                        <span>{{ $label }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                            @if ($duplicate['candidates'])
                                                <fieldset class="mt-4 border-0 p-0">
                                                    <legend class="text-sm font-semibold">Person to update</legend>
                                                    <p class="text-xs text-gray-600 dark:text-gray-300">Required when “Update match” is selected.</p>
                                                    <div class="mt-2 grid gap-2">
                                                        @foreach ($duplicate['candidates'] as $candidate)
                                                            <label class="inline-flex items-center gap-2">
                                                                <input
                                                                    type="radio"
                                                                    name="candidate-{{ $duplicate['id'] }}"
                                                                    value="{{ $candidate['id'] }}"
                                                                    @checked(($duplicateCandidates[$duplicate['id']] ?? null) === $candidate['id'])
                                                                    wire:change="setDuplicateCandidate({{ Illuminate\Support\Js::from((string) $duplicate['id']) }}, {{ Illuminate\Support\Js::from((string) $candidate['id']) }})"
                                                                >
                                                                <span>{{ $candidate['label'] }}</span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </fieldset>
                                            @endif
                                            @if ($duplicate['household_candidates'])
                                                <fieldset class="mt-4 border-0 p-0">
                                                    <legend class="text-sm font-semibold">Household to use</legend>
                                                    <p class="text-xs text-gray-600 dark:text-gray-300">Choose the existing household for this row, or skip the row.</p>
                                                    <div class="mt-2 grid gap-2">
                                                        @foreach ($duplicate['household_candidates'] as $candidate)
                                                            <label class="inline-flex items-center gap-2">
                                                                <input
                                                                    type="radio"
                                                                    name="household-candidate-{{ $duplicate['id'] }}"
                                                                    value="{{ $candidate['id'] }}"
                                                                    @checked(($duplicateHouseholdCandidates[$duplicate['id']] ?? null) === $candidate['id'])
                                                                    wire:change="setDuplicateHouseholdCandidate({{ Illuminate\Support\Js::from((string) $duplicate['id']) }}, {{ Illuminate\Support\Js::from((string) $candidate['id']) }})"
                                                                >
                                                                <span>{{ $candidate['label'] }}</span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </fieldset>
                                            @endif
                                            @error('duplicateResolutions.'.$duplicate['id']) <p role="alert" class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror
                                            @error('duplicateCandidates.'.$duplicate['id']) <p role="alert" class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror
                                            @error('duplicateHouseholdCandidates.'.$duplicate['id']) <p role="alert" class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror
                                        </fieldset>
                                    @endforeach
                                </div>
                                <x-filament::button wire:click="saveDuplicateResolutions" wire:loading.attr="disabled" wire:target="saveDuplicateResolutions">
                                    Save duplicate decisions
                                </x-filament::button>
                            </div>
                        @elseif ($import['canCommit'])
                            <div class="maestro-portability-callout" role="status">
                                <p>No possible duplicates remain. The reviewed plan is ready to commit.</p>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($step === 'commit')
                    <div class="space-y-5">
                        @php
                            $progressTotal = max(1, (int) ($import['progress']['total'] ?? 0));
                            $progressProcessed = (int) ($import['progress']['processed'] ?? 0);
                            $progressPercent = min(100, (int) round(($progressProcessed / $progressTotal) * 100));
                        @endphp
                        <div aria-live="polite" aria-atomic="true">
                            <div class="mb-2 flex justify-between gap-3 text-sm">
                                <span>{{ str($import['status'])->headline() }}</span>
                                <span>{{ $progressProcessed }} of {{ $import['progress']['total'] ?? 0 }} rows</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progressPercent }}" aria-label="Import progress">
                                <div class="h-full rounded-full bg-primary-600 transition-[width]" style="width: {{ $progressPercent }}%"></div>
                            </div>
                        </div>
                        @if ($import['canCommit'])
                            <div class="maestro-portability-danger-zone">
                                <div>
                                    <h3>Apply the reviewed CRM changes</h3>
                                    <p>This is the first step that writes records. Your current plan and duplicate choices are revalidated at commit time.</p>
                                </div>
                                <x-filament::button wire:click="commitImport" color="warning" wire:loading.attr="disabled" wire:target="commitImport">
                                    Commit reviewed rows
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($step === 'outcome')
                    <div class="space-y-5" aria-live="polite">
                        <div class="maestro-portability-summary" aria-label="Import outcome">
                            @foreach (['created' => 'Created', 'updated' => 'Updated', 'skipped' => 'Skipped', 'failed' => 'Needs retry'] as $key => $label)
                                <div><strong>{{ number_format($import['progress'][$key] ?? 0) }}</strong><span>{{ $label }}</span></div>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap gap-3">
                            @if ($import['canResume'])
                                <x-filament::button wire:click="resumeImport" icon="heroicon-o-arrow-path" wire:loading.attr="disabled" wire:target="resumeImport">
                                    Resume failed rows
                                </x-filament::button>
                            @endif
                            @if ($import['errorReportUrl'])
                                <x-filament::button tag="a" :href="$import['errorReportUrl']" color="gray" icon="heroicon-o-document-arrow-down">
                                    Download safe error CSV
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if ($export)
            <x-filament::section>
                <x-slot name="heading">Portable CRM bundle</x-slot>
                <x-slot name="description">Export {{ substr($export['id'], -8) }} · {{ str($export['status'])->headline() }}</x-slot>
                <div class="flex flex-wrap items-center justify-between gap-4" aria-live="polite">
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ number_format($export['progress']['processed'] ?? 0) }} of {{ number_format($export['progress']['total'] ?? 0) }} records prepared.
                        @if ($export['expiresAt']) Download expires {{ $export['expiresAt'] }}. @endif
                    </p>
                    @if ($export['downloadUrl'])
                        <x-filament::button tag="a" :href="$export['downloadUrl']" icon="heroicon-o-arrow-down-tray">
                            Download private .maestro bundle
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif

        @if (($import && in_array($import['status'], ['queued', 'importing'], true)) || ($export && in_array($export['status'], ['queued', 'exporting'], true)))
            <div wire:poll.4s="refreshProgress" role="status" class="text-sm text-gray-500">
                Progress updates automatically. You can safely leave and return later.
            </div>
        @elseif ($import || $export)
            <x-filament::button wire:click="refreshProgress" color="gray" size="sm" icon="heroicon-o-arrow-path">
                Refresh progress
            </x-filament::button>
        @endif
    </div>
</x-filament-panels::page>
