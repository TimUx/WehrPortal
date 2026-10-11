@props(['key'])
{{-- The icon of a module, used on the start page and in the app launcher --}}
<svg {{ $attributes->merge(['class' => 'h-6 w-6']) }} fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
    @if($key === 'fahrzeugbuchung')
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/>
                                            <circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>
    @elseif($key === 'kleiderkammer')
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5l-3 5h4l3-3m6 0 3 3h4l-3-5h-4M9 3l3 3 3-3M3 8v13h18V8"/>
    @elseif($key === 'maengelmanagement')
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
    @elseif($key === 'schichtplan')
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M3 10h18M3 15h18M3 20h18M9 5v15M15 5v15"/>
    @elseif($key === 'ausruestung')
        <path stroke-linecap="round" stroke-linejoin="round" d="M11.4 4.6a3 3 0 0 0-4 3.9L3 13l-.5 3.5L6 16l4.5-4.4a3 3 0 0 0 3.9-4l-2 2-1.9-.5-.5-1.9 2-2zM14 14l6 6M17 17l-2 2"/>
    @elseif($key === 'mitteilungen')
        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5.88V19.24a1.76 1.76 0 01-3.42.59l-2.15-6.15M18 13a3 3 0 100-6M5.44 13.68A4 4 0 017 6h1.83c4.1 0 7.62-1.23 9.17-3v16c-1.55-1.77-5.07-3-9.17-3H7a3.99 3.99 0 01-1.56-.32z"/>
    @elseif($key === 'lehrgaenge')
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42A12 12 0 0119 17.5 11.95 11.95 0 0012 20a11.95 11.95 0 00-7-2.5 12 12 0 01.84-6.92L12 14zm-4 6v-7.5l4-2.22"/>
    @elseif($key === 'kalender')
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zM9 15h.01M12 15h.01M15 15h.01"/>
    @elseif($key === 'einsatzbericht')
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2zM13 3v5a1 1 0 001 1h5"/>
    @elseif($key === 'uebungsdienste')
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
    @elseif($key === 'finanzen')
        <rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path stroke-linecap="round" d="M6 12h.01M18 12h.01"/>
    @elseif($key === 'verbandbuch')
        <rect x="3" y="7" width="18" height="13" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M12 10.5v6M9 13.5h6"/>
    @elseif($key === 'wartung')
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M12 11v3l2 2"/><circle cx="12" cy="14" r="4"/>
    @else
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
    @endif
</svg>
