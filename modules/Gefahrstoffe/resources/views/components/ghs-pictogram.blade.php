<svg class="h-16 w-16 shrink-0" viewBox="0 0 100 100" role="img" aria-label="{{ $code }}">
    <rect x="18" y="18" width="64" height="64" transform="rotate(45 50 50)" fill="white" stroke="#e31b23" stroke-width="4" />
    <g fill="#111827" stroke="#111827" stroke-linecap="round" stroke-linejoin="round">
        @switch($code)
            @case('GHS01')
                <circle cx="48" cy="56" r="13" />
                <path d="M56 45l7-10 4 2-3 7 8-2 2 4-11 5M68 26v-5m8 10 4-4M61 27l-3-5M31 38l-5-3m4 31-6 2" fill="none" stroke-width="3" />
                @break
            @case('GHS02')
                <path d="M48 75c-13 0-22-9-20-21 1-8 7-14 12-19 1 7 4 10 7 12 1-12 8-20 17-28-1 12 2 18 7 25 4 6 6 11 4 18-2 8-10 13-27 13z" />
                <path d="M49 68c-6 0-10-4-9-9 0-4 4-7 7-10 1 5 3 6 5 7 1-5 4-8 8-12 0 6 4 10 4 15 0 5-5 9-15 9z" fill="white" stroke="white" />
                @break
            @case('GHS03')
                <circle cx="50" cy="61" r="12" fill="none" stroke-width="4" />
                <path d="M49 46c-6-4-6-10-3-15 1 4 4 5 6 6 0-7 4-12 9-17 0 7 2 11 5 15 3 5 2 10-1 13-5-4-10-5-16-2z" />
                @break
            @case('GHS04')
                <path d="M35 42h34a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H35a8 8 0 0 1-8-8v-4a8 8 0 0 1 8-8zm34 4v12M30 50h-5v4h5m8-12v20" stroke-width="3" />
                <path d="M43 40v-5h10v5m-5-5v-4" fill="none" stroke-width="3" />
                @break
            @case('GHS05')
                <path d="M31 28l7-4 11 17-7 4zm24-7 7 4-10 18-7-4z" />
                <path d="M42 48c-2 3-2 5 0 6s4-1 4-4m6-1c-2 3-2 5 0 6s4-1 4-4M29 70h40l-5-9H40c-5 0-8 3-11 9zM48 62l-8-5-7 2 8 9m18-22-2 8 5-2 1-7" />
                @break
            @case('GHS06')
                <path d="M31 74l18-18m2 0 18 18m-38-3 4 4m30-4-4 4" fill="none" stroke-width="5" />
                <path d="M50 28c-13 0-21 9-21 22v11l5 8h32l5-8V50c0-13-8-22-21-22z" />
                <ellipse cx="42" cy="49" rx="5" ry="7" fill="white" />
                <ellipse cx="58" cy="49" rx="5" ry="7" fill="white" />
                <path d="M47 58h6l-3 5zm-9 12v-5h5v5m9 0v-5h5v5" fill="white" stroke="white" />
                @break
            @case('GHS07')
                <path d="M46 30h8l-2 28h-4z" />
                <circle cx="50" cy="67" r="5" />
                @break
            @case('GHS08')
                <circle cx="50" cy="30" r="9" />
                <path d="M31 72c2-13 4-22 11-27l8 6 8-6c8 5 10 14 12 27H31z" />
                <path d="M50 49l2.5 5.5 6 .5-4.5 4 1.5 6-5.5-3-5.5 3 1.5-6-4.5-4 6-.5z" fill="white" stroke="white" />
                @break
            @case('GHS09')
                <path d="M39 61V36l-9-9m9 17-9-7m9 16 9-10m-9 1 8-8m-8 20-8-7" fill="none" stroke-width="4" />
                <path d="M61 67c6-7 12-7 18-3-6 5-12 6-18 3zm0 0-7-6v12z" />
                <circle cx="73" cy="65" r="1.5" fill="white" stroke="white" />
                <path d="M29 75h47" fill="none" stroke-width="3" />
                @break
        @endswitch
    </g>
</svg>
