@php($configuration = $this->calendarConfiguration())
@php($managedSeries = $configuration['canManage'] ? $this->managedSeriesSummaries() : [])

<x-filament-panels::page>
    <div
        class="maestro-calendar-shell"
        data-maestro-calendar
        data-timezone="{{ $configuration['timezone'] }}"
        data-can-manage="{{ $configuration['canManage'] ? 'true' : 'false' }}"
        wire:ignore
    >
        <div class="maestro-calendar-filters" aria-label="Calendar filters">
            <label>
                <span>Search</span>
                <input type="search" data-calendar-filter="q" placeholder="Name or lesson" autocomplete="off" />
            </label>

            <label>
                <span>Teacher</span>
                <select data-calendar-filter="teacher_id">
                    <option value="">All visible teachers</option>
                    @foreach ($configuration['teachers'] as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>

            @if ($configuration['canManage'])
                <label>
                    <span>Room</span>
                    <select data-calendar-filter="room_id">
                        <option value="">All rooms</option>
                        @foreach ($configuration['rooms'] as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label>
                <span>Type</span>
                <select data-calendar-filter="kind">
                    <option value="">All types</option>
                    <option value="private_lesson">Private lesson</option>
                    <option value="group_class">Group class</option>
                    <option value="open_class">Open class</option>
                    <option value="workshop">Workshop</option>
                    <option value="camp">Camp</option>
                    <option value="recital">Recital</option>
                    <option value="general">General</option>
                    <option value="closure">Closure</option>
                </select>
            </label>

            <label>
                <span>Holds</span>
                <select data-calendar-filter="holds">
                    <option value="include">Include holds</option>
                    <option value="exclude">Hide holds</option>
                    <option value="only">Holds only</option>
                </select>
            </label>
        </div>

        <div class="maestro-calendar-status" data-calendar-status role="status" aria-live="polite"></div>
        <div data-calendar-root aria-label="Studio events calendar"></div>

        <dialog class="maestro-calendar-dialog" data-calendar-dialog aria-labelledby="calendar-event-title">
            <form method="dialog">
                <button class="maestro-calendar-dialog-close" value="close" aria-label="Close event details">×</button>
                <p class="maestro-calendar-kicker" data-calendar-kind></p>
                <h2 id="calendar-event-title" data-calendar-title></h2>
                <dl data-calendar-details></dl>
                <div class="maestro-calendar-dialog-actions" data-calendar-manager-actions hidden>
                    <button type="button" data-calendar-operation="rescheduleEvent">Reschedule</button>
                    <button type="button" data-calendar-operation="manageRoster">Roster</button>
                    <button type="button" data-calendar-operation="cloneEvent">Clone series</button>
                    <button type="button" data-calendar-operation="manageHold" data-hold-operation="convert">Convert hold</button>
                    <button type="button" data-calendar-operation="manageHold" data-hold-operation="release">Release hold</button>
                    <button type="button" data-calendar-operation="changeEventStatus" data-status-operation="cancel">Cancel</button>
                    <button type="button" data-calendar-operation="changeEventStatus" data-status-operation="restore">Restore</button>
                </div>
                <div class="maestro-calendar-dialog-actions">
                    <button value="close">Done</button>
                </div>
            </form>
        </dialog>
    </div>

    @if ($configuration['canManage'] && $slotSuggestions !== [])
        <section class="maestro-calendar-panel" aria-labelledby="slot-suggestions-heading">
            <div>
                <p class="maestro-calendar-kicker">Availability search</p>
                <h2 id="slot-suggestions-heading">Suggested times</h2>
                <p>Times use {{ $configuration['timezone'] }}. Warnings are shown before you choose a slot.</p>
            </div>
            <ol class="maestro-slot-list">
                @foreach ($slotSuggestions as $slot)
                    <li>
                        <div>
                            <strong>{{ \Carbon\CarbonImmutable::parse($slot['starts_at'])->setTimezone($configuration['timezone'])->isoFormat('ddd, MMM D · h:mm A') }}</strong>
                            <span>{{ \Carbon\CarbonImmutable::parse($slot['ends_at'])->setTimezone($configuration['timezone'])->isoFormat('h:mm A') }}</span>
                        </div>
                        @if ($slot['warnings'] === [])
                            <span class="maestro-calendar-pill is-clear">No warnings</span>
                        @else
                            <span class="maestro-calendar-pill is-warning">{{ count($slot['warnings']) }} {{ \Illuminate\Support\Str::plural('warning', count($slot['warnings'])) }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @if ($configuration['canManage'])
        <section class="maestro-calendar-panel" aria-labelledby="roster-overview-heading">
            <div>
                <p class="maestro-calendar-kicker">Upcoming series</p>
                <h2 id="roster-overview-heading">Roster overview</h2>
                <p>Confirmed and waitlisted enrollments across active upcoming series.</p>
            </div>
            <div class="maestro-roster-grid">
                @forelse ($managedSeries as $series)
                    <article>
                        <div>
                            <h3>{{ $series['title'] }}</h3>
                            <p>{{ $series['confirmed'] }} confirmed · {{ $series['waitlisted'] }} waitlisted</p>
                        </div>
                        <ul aria-label="{{ $series['title'] }} roster">
                            @forelse ($series['roster'] as $member)
                                <li><span>{{ $member['name'] }}</span><span>{{ \Illuminate\Support\Str::headline($member['status']) }}</span></li>
                            @empty
                                <li><span>No students enrolled</span></li>
                            @endforelse
                        </ul>
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="gray"
                            icon="heroicon-o-user-group"
                            wire:click="mountAction('manageRoster', { series_id: '{{ $series['id'] }}' })"
                        >
                            Manage roster
                        </x-filament::button>
                    </article>
                @empty
                    <p>No upcoming series are available.</p>
                @endforelse
            </div>
        </section>
    @endif
</x-filament-panels::page>
