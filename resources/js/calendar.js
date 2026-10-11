import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import luxonPlugin from '@fullcalendar/luxon3';
import deLocale from '@fullcalendar/core/locales/de';

let calendar = null;

// Flat line icons (Lucide, ISC license); static markup, never user data
const ICONS = {
    car: '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
    trailer: '<rect x="1" y="7" width="16" height="9" rx="1"/><circle cx="5" cy="17" r="2"/><circle cx="13" cy="17" r="2"/><path d="M5 16h8"/><path d="M17 11h2.5a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H17"/><line x1="22" y1="12" x2="22" y2="14"/>',
    target: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
};

function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.classList.add('fc-ev-icon');
    svg.innerHTML = ICONS[name];
    return svg;
}

// Text goes in via textContent so booking data can't inject markup
function el(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function listContent(props) {
    const row = el('div', 'fc-ev-list');
    if (props.status === 'pending') row.append(el('span', 'fc-ev-pending', 'Angefragt'));
    const vehicleIcon = props.vehicleType === 'trailer' ? 'trailer' : 'car';
    [[vehicleIcon, props.vehicleShort], ['target', props.purpose], ['users', props.userName]].forEach(([name, text]) => {
        const item = el('span', 'fc-ev-item');
        item.append(icon(name), el('span', '', text));
        row.append(item);
    });
    return row;
}

function weekContent(props, compact) {
    const box = el('div', compact ? 'fc-ev-week fc-ev-week-compact' : 'fc-ev-week');
    box.append(el('div', 'fc-ev-vehicle', props.vehicleShort));
    if (!compact) {
        box.append(el('div', 'fc-ev-purpose', props.purpose), el('div', 'fc-ev-user', props.userName));
    }
    return box;
}

function buildEventsUrl(vehicleIds) {
    const base = document.getElementById('booking-calendar')?.dataset?.eventsUrl ?? '/fahrzeugbuchung/buchungen/termine';
    const url = new URL(base, window.location.origin);
    if (vehicleIds && vehicleIds.length > 0) {
        vehicleIds.forEach(id => url.searchParams.append('vehicles[]', id));
    }
    return url.toString();
}

function initCalendar() {
    const el = document.getElementById('booking-calendar');
    if (!el || calendar) return;

    const isMobile = window.innerWidth < 430;

    const initialView = el.dataset.initialView || 'timeGridWeek';
    // Month/list grow with their content; the week grid scrolls inside a fixed height
    const heightFor = (viewType) => (viewType === 'timeGridWeek' ? 650 : 'auto');

    calendar = new Calendar(el, {
        plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, luxonPlugin],
        locale: deLocale,
        // Named time zones need the Luxon plugin; without it FullCalendar silently shows UTC
        timeZone: 'Europe/Berlin',
        initialView,
        firstDay: 1,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listWeek',
        },
        buttonText: {
            today: 'Heute',
            month: 'Monat',
            week: 'Woche',
            list: 'Liste',
        },
        scrollTime: '06:00:00',
        slotMinTime: '00:00:00',
        slotMaxTime: '24:00:00',
        allDaySlot: false,
        height: heightFor(initialView),
        views: isMobile ? {
            // Phones: shorter title, e.g. "28. Sept. – 4. Okt."
            timeGridWeek: {
                titleFormat: { month: 'short', day: 'numeric' },
                // "M, 28." instead of "Mo. 28.9." so seven columns fit
                dayHeaderFormat: { weekday: 'narrow', day: 'numeric' },
            },
            listWeek: { titleFormat: { month: 'short', day: 'numeric' } },
        } : {},
        events: buildEventsUrl([]),

        // Month view on mobile: show colored dots, no text
        eventContent: function(arg) {
            const props = arg.event.extendedProps;

            switch (arg.view.type) {
                case 'listWeek':
                    return { domNodes: [listContent(props)] };
                case 'timeGridWeek':
                    // Phones: vehicle name only, the columns are too narrow for more
                    return { domNodes: [weekContent(props, isMobile)] };
                case 'dayGridMonth':
                    if (isMobile) {
                        return {
                            html: `<span class="fc-event-dot" style="background-color:${arg.event.backgroundColor}"></span>`,
                        };
                    }
            }
            return true; // default rendering
        },

        // FullCalendar's own fc-event-past works per day; mark bookings that have actually ended
        eventClassNames: function(arg) {
            return arg.event.end && arg.event.end < new Date() ? ['fc-ev-over'] : [];
        },

        eventClick: function(info) {
            const props = info.event.extendedProps;
            window.Livewire?.dispatch('booking-detail-open', { bookingId: props.bookingId });
        },

        dateClick: function(info) {
            if (calendar.view.type === 'dayGridMonth' && isMobile) {
                // On mobile month view, show day list instead of opening form
                window.Livewire?.dispatch('day-list-open', { date: info.dateStr });
            } else {
                window.Livewire?.dispatch('open-booking-form', {
                    date: info.dateStr,
                    vehicleId: null,
                });
            }
        },

        viewDidMount: function(arg) {
            // height is not a view-specific option in FullCalendar, so switch it here
            const height = heightFor(arg.view.type);
            // Deferred: options can't be changed while FullCalendar is still rendering the view
            setTimeout(() => {
                if (calendar && calendar.getOption('height') !== height) {
                    calendar.setOption('height', height);
                    if (height !== 'auto') calendar.scrollToTime('06:00:00');
                }
            });
            window.Livewire?.dispatch('calendar-view-changed', { view: arg.view.type });
        },
    });

    calendar.render();
}

// Initialize when DOM is ready after Livewire boots
document.addEventListener('livewire:init', () => {
    // Small delay to ensure the wire:ignore div is rendered
    requestAnimationFrame(initCalendar);

    window.Livewire.on('calendar-filter-changed', ({ vehicleIds }) => {
        if (calendar) {
            calendar.setOption('events', buildEventsUrl(vehicleIds));
            calendar.refetchEvents();
        }
    });

    window.Livewire.on('calendar-refresh', () => {
        if (calendar) {
            calendar.refetchEvents();
        }
    });
});

// Re-init if the calendar div appears (e.g., after navigation)
document.addEventListener('livewire:navigated', () => {
    calendar = null;
    requestAnimationFrame(initCalendar);
});
