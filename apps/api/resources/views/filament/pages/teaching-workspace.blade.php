@php($lessons = $this->recentLessons())
@php($notes = $this->recentNoteCards())

<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($lessons as $lesson)
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $lesson['when'] }}</p>
                        <h2 class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{{ $lesson['title'] }}</h2>
                    </div>
                    @if ($lesson['overdue'])
                        <span class="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-700 dark:bg-warning-400/10 dark:text-warning-300">Overdue</span>
                    @endif
                </div>
                <div class="mt-4 flex items-center justify-between text-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ $lesson['recorded'] }} of {{ $lesson['roster'] }} recorded</span>
                    <span class="font-medium {{ $lesson['recorded'] === $lesson['roster'] ? 'text-success-600 dark:text-success-400' : 'text-primary-600 dark:text-primary-400' }}">
                        {{ $lesson['recorded'] === $lesson['roster'] ? 'Complete' : 'Needs attendance' }}
                    </span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-white/10">
                    <x-filament::button
                        type="button"
                        size="sm"
                        icon="heroicon-o-clipboard-document-check"
                        wire:click="mountAction('takeAttendance', { occurrence_id: '{{ $lesson['id'] }}' })"
                    >
                        Take attendance
                    </x-filament::button>
                    <x-filament::button
                        type="button"
                        size="sm"
                        color="gray"
                        icon="heroicon-o-pencil-square"
                        wire:click="mountAction('addLessonNote', { occurrence_id: '{{ $lesson['id'] }}' })"
                    >
                        Add note
                    </x-filament::button>
                    @if ($lesson['recorded'] < $lesson['roster'])
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="gray"
                            icon="heroicon-o-bolt"
                            wire:click="mountAction('expressPresent', { occurrence_id: '{{ $lesson['id'] }}' })"
                        >
                            Everyone present
                        </x-filament::button>
                    @endif
                </div>
            </section>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/15">
                <h2 class="font-semibold text-gray-950 dark:text-white">No recent lessons need attention</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Ended lessons assigned to you will appear here for 90 days.</p>
            </div>
        @endforelse
    </div>

    <section class="maestro-teaching-notes" aria-labelledby="recent-notes-heading">
        <div class="maestro-teaching-notes-heading">
            <div>
                <p class="maestro-calendar-kicker">Learning record</p>
                <h2 id="recent-notes-heading">Recent lesson notes</h2>
                <p>Delivery and file access remain separate, explicit operations.</p>
            </div>
        </div>

        <div class="maestro-note-grid">
            @forelse ($notes as $note)
                <article>
                    <header>
                        <div>
                            <p>{{ $note['lesson'] }}{{ $note['student'] ? ' · '.$note['student'] : '' }}</p>
                            <h3>{{ $note['title'] }}</h3>
                        </div>
                        <span class="maestro-note-audience">{{ \Illuminate\Support\Str::headline($note['audience']) }}</span>
                    </header>
                    <p class="maestro-note-summary">{{ $note['summary'] }}</p>

                    @if ($note['attachments'] !== [])
                        <ul class="maestro-attachment-list" aria-label="Attachments for {{ $note['title'] }}">
                            @foreach ($note['attachments'] as $attachment)
                                <li>
                                    <div>
                                        <span>{{ $attachment['name'] }}</span>
                                        <small>Revision {{ $attachment['revision'] }} · {{ \Illuminate\Support\Str::headline($attachment['status']) }}</small>
                                    </div>
                                    <div>
                                        @if ($attachment['download_url'])
                                            <a href="{{ $attachment['download_url'] }}" target="_blank" rel="noopener noreferrer">Download</a>
                                        @endif
                                        @if ($attachment['can_rescan'])
                                            <button type="button" wire:click="mountAction('rescanNoteAttachment', { attachment_id: '{{ $attachment['id'] }}' })">Retry scan</button>
                                        @endif
                                        @if ($attachment['can_retire'])
                                            <button type="button" wire:click="mountAction('retireNoteAttachment', { attachment_id: '{{ $attachment['id'] }}' })">Retire</button>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <footer>
                        @if ($note['can_attach'])
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="gray"
                                icon="heroicon-o-paper-clip"
                                wire:click="mountAction('uploadNoteAttachment', { note_id: '{{ $note['id'] }}' })"
                            >
                                Attach file
                            </x-filament::button>
                        @endif
                        @if ($note['can_deliver'])
                            <x-filament::button
                                type="button"
                                size="sm"
                                icon="heroicon-o-paper-airplane"
                                wire:click="mountAction('deliverLessonNote', { note_id: '{{ $note['id'] }}' })"
                            >
                                Preview delivery
                            </x-filament::button>
                        @endif
                    </footer>
                </article>
            @empty
                <div class="maestro-note-empty">
                    <h3>No lesson notes yet</h3>
                    <p>Add a note from a completed lesson. Nothing is delivered automatically.</p>
                </div>
            @endforelse
        </div>
    </section>
</x-filament-panels::page>
