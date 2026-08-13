<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">Retention guardrails</x-slot>
            <x-slot name="description">Explicit windows apply before any data becomes purge-eligible.</x-slot>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt>Export availability</dt><dd>{{ $retentionPolicy->export_ttl_hours }} hours</dd></div>
                <div class="flex justify-between gap-3"><dt>Cooling-off</dt><dd>{{ $retentionPolicy->deletion_cooling_off_days }} days</dd></div>
                <div class="flex justify-between gap-3"><dt>Quarantine</dt><dd>{{ $retentionPolicy->deletion_quarantine_days }} days</dd></div>
                <div class="flex justify-between gap-3"><dt>Legal hold</dt><dd>{{ $retentionPolicy->legal_hold ? 'Active' : 'Not active' }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">Portable exports</x-slot>
            <x-slot name="description">Private encrypted archives are short-lived and downloads require reauthorization.</x-slot>
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($exports as $export)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <div class="font-medium">{{ $export->created_at?->isoFormat('MMM D, YYYY · h:mm A') }}</div>
                            <div class="text-sm text-gray-500">{{ str($export->status->value)->headline() }} · {{ count($export->completed_datasets ?? []) }} datasets</div>
                        </div>
                        @if ($export->status->value === 'ready')
                            <x-filament::button size="sm" color="gray" wire:click="runRestoreDrill('{{ $export->getKey() }}')">Verify restore</x-filament::button>
                        @endif
                    </div>
                @empty
                    <p class="py-4 text-sm text-gray-500">No exports have been requested.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Deletion lifecycle</x-slot>
        <x-slot name="description">Requests move through cooling-off, independent administrator approval, suspension, and quarantine. Automation never performs a raw hard delete.</x-slot>
        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($deletions as $deletion)
                <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div>
                        <div class="font-medium">{{ str($deletion->status->value)->headline() }}</div>
                        <div class="text-sm text-gray-500">{{ $deletion->reason }}</div>
                    </div>
                    <div class="flex gap-2">
                        @if (in_array($deletion->status->value, ['cooling_off', 'approved'], true))
                            <x-filament::button size="sm" color="gray" wire:click="cancelDeletion('{{ $deletion->getKey() }}')">Cancel</x-filament::button>
                        @endif
                        @if (in_array($deletion->status->value, ['suspended', 'quarantined', 'purge_eligible'], true))
                            <x-filament::button size="sm" color="success" wire:click="restoreTenant('{{ $deletion->getKey() }}')">Restore access</x-filament::button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="py-4 text-sm text-gray-500">No deletion review is active.</p>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Restore verification drills</x-slot>
        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($restoreDrills as $drill)
                <div class="flex items-center justify-between gap-3 py-3 text-sm">
                    <span>{{ $drill->created_at?->isoFormat('MMM D, YYYY · h:mm A') }}</span>
                    <span>{{ str($drill->status->value)->headline() }}</span>
                </div>
            @empty
                <p class="py-4 text-sm text-gray-500">No restore drills have run.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
