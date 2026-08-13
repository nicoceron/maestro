import 'temporal-polyfill/global';
import { Calendar } from 'fullcalendar';
import dayGridPlugin from 'fullcalendar/daygrid';
import interactionPlugin from 'fullcalendar/interaction';
import listPlugin from 'fullcalendar/list';
import timeGridPlugin from 'fullcalendar/timegrid';
import breezyThemePlugin from 'fullcalendar/themes/breezy';

import 'fullcalendar/skeleton.css';
import 'fullcalendar/themes/breezy/theme.css';
import 'fullcalendar/themes/breezy/palettes/indigo.css';

const calendarInstances = new WeakMap();

function calendarComponent(element) {
    const livewireRoot = element.closest('[wire\\:id]');
    const id = livewireRoot?.getAttribute('wire:id');

    return id ? window.Livewire?.find(id) : null;
}

function filtersFor(element) {
    return Object.fromEntries(
        [...element.querySelectorAll('[data-calendar-filter]')].map((field) => [field.dataset.calendarFilter, field.value]),
    );
}

function detailRow(label, value) {
    if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
        return '';
    }

    const text = Array.isArray(value) ? value.join(', ') : String(value);
    const term = document.createElement('dt');
    const description = document.createElement('dd');
    term.textContent = label;
    description.textContent = text;
    const container = document.createElement('div');
    container.append(term, description);

    return container;
}

function detailLink(label, value) {
    if (!value) {
        return '';
    }

    let url;

    try {
        url = new URL(value);
    } catch {
        return '';
    }

    if (!['http:', 'https:'].includes(url.protocol)) {
        return '';
    }

    const term = document.createElement('dt');
    const description = document.createElement('dd');
    const link = document.createElement('a');
    term.textContent = label;
    link.href = url.toString();
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = 'Join online';
    description.append(link);
    const container = document.createElement('div');
    container.append(term, description);

    return container;
}

function openEventDetails(element, event) {
    const dialog = element.querySelector('[data-calendar-dialog]');
    const properties = event.extendedProps;
    element.querySelector('[data-calendar-title]').textContent = event.title;
    element.querySelector('[data-calendar-kind]').textContent = `${properties.kind.replaceAll('_', ' ')} · ${properties.status}`;
    const details = element.querySelector('[data-calendar-details]');
    details.replaceChildren();

    [
        detailRow('When', `${event.start?.toLocaleString() ?? ''} – ${event.end?.toLocaleTimeString() ?? ''}`),
        detailRow('Teacher', properties.teachers),
        detailRow('Room', properties.rooms),
        detailRow('Attendance', properties.participants === null ? null : `${properties.participants} of ${properties.capacity}`),
        detailRow('Timezone', properties.timezone),
        detailLink('Online lesson', properties.onlineJoinUrl),
        detailRow('Hold expires', properties.holdExpiresAt ? new Date(properties.holdExpiresAt).toLocaleString() : null),
    ].filter(Boolean).forEach((row) => details.append(row));

    const managerActions = element.querySelector('[data-calendar-manager-actions]');
    if (managerActions) {
        managerActions.hidden = !properties.canManage;
        managerActions.querySelectorAll('[data-calendar-operation]').forEach((button) => {
            button.dataset.occurrenceId = event.id;
            button.dataset.seriesId = properties.seriesId;
            const statusOperation = button.dataset.statusOperation;
            const holdOperation = button.dataset.holdOperation;
            button.hidden = (statusOperation === 'restore' && properties.status !== 'canceled')
                || (statusOperation === 'cancel' && properties.status === 'canceled')
                || (holdOperation && !properties.isHold);
        });
    }

    dialog.showModal();
}

function initializeCalendar(element) {
    if (calendarInstances.has(element)) {
        return;
    }

    const root = element.querySelector('[data-calendar-root]');
    const status = element.querySelector('[data-calendar-status]');
    const component = calendarComponent(element);

    if (!root || !component) {
        return;
    }

    const calendar = new Calendar(root, {
        plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, breezyThemePlugin],
        initialView: window.matchMedia('(max-width: 720px)').matches ? 'listWeek' : 'timeGridWeek',
        timeZone: element.dataset.timezone,
        nowIndicator: true,
        navLinks: true,
        height: 720,
        scrollTime: '07:00:00',
        eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
        headerToolbar: {
            start: 'prev,next today',
            center: 'title',
            end: 'dayGridMonth,timeGridWeek,timeGridDay,timeline,agenda,printCalendar',
        },
        buttons: {
            timeline: {
                text: 'Timeline',
                hint: 'Show a chronological month timeline',
                click: () => calendar.changeView('listMonth'),
            },
            agenda: {
                text: 'Agenda',
                hint: 'Show the weekly agenda',
                click: () => calendar.changeView('listWeek'),
            },
            printCalendar: {
                text: 'Print',
                hint: 'Print the current calendar view',
                click: () => window.print(),
            },
        },
        events: async (info, success, failure) => {
            status.textContent = 'Loading calendar…';

            try {
                const events = await component.call('calendarEvents', info.startStr, info.endStr, filtersFor(element));
                success(events);
                status.textContent = events.length === 0 ? 'No events in this date range.' : `${events.length} events loaded.`;
            } catch (error) {
                status.textContent = 'The calendar could not be loaded. Try again.';
                failure(error instanceof Error ? error : new Error('Calendar request failed.'));
            }
        },
        eventClick: ({ event }) => openEventDetails(element, event),
    });

    element.querySelectorAll('[data-calendar-filter]').forEach((field) => {
        const eventName = field.matches('input[type="search"]') ? 'input' : 'change';
        let timeout;
        field.addEventListener(eventName, () => {
            clearTimeout(timeout);
            timeout = setTimeout(() => calendar.refetchEvents(), eventName === 'input' ? 250 : 0);
        });
    });

    element.querySelector('[data-calendar-dialog]')?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-calendar-operation]');
        if (!button || !component) {
            return;
        }

        const argumentsForAction = {
            occurrence_id: button.dataset.occurrenceId,
            series_id: button.dataset.seriesId,
        };
        if (button.dataset.statusOperation) {
            argumentsForAction.operation = button.dataset.statusOperation;
        }
        if (button.dataset.holdOperation) {
            argumentsForAction.operation = button.dataset.holdOperation;
        }
        element.querySelector('[data-calendar-dialog]')?.close();
        await component.call('mountAction', button.dataset.calendarOperation, argumentsForAction);
    });

    calendar.render();
    calendarInstances.set(element, calendar);
}

window.addEventListener('maestro-calendar-refresh', () => {
    document.querySelectorAll('[data-maestro-calendar]').forEach((element) => {
        calendarInstances.get(element)?.refetchEvents();
    });
});

function initializeCalendars() {
    document.querySelectorAll('[data-maestro-calendar]').forEach(initializeCalendar);
}

document.addEventListener('livewire:init', initializeCalendars);
document.addEventListener('livewire:navigated', initializeCalendars);
document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', initializeCalendars) : initializeCalendars();
