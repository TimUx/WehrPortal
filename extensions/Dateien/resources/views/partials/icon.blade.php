{{-- File browser icons: folder, file, space --}}
@if($kind === 'folder')
    <svg class="{{ $class ?? 'h-5 w-5' }} shrink-0 text-amber-400" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 6a2 2 0 0 1 2-2h4.2a2 2 0 0 1 1.4.6L12 6h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6z"/></svg>
@elseif($kind === 'space')
    <svg class="{{ $class ?? 'h-5 w-5' }} shrink-0 text-fw-navy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h18M5 12l2-6h10l2 6M5 12v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6M16 15.5h.01"/></svg>
@elseif($kind === 'star')
    <svg class="{{ $class ?? 'h-5 w-5' }} shrink-0 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
@elseif($kind === 'clock')
    <svg class="{{ $class ?? 'h-5 w-5' }} shrink-0 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
@elseif($kind === 'share')
    <svg class="{{ $class ?? 'h-5 w-5' }} shrink-0 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="m8.2 10.8 7.6-3.6M8.2 13.2l7.6 3.6"/></svg>
@elseif($kind === 'trash')
    <svg class="{{ $class ?? 'h-5 w-5' }} shrink-0 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12M9 7V4h6v3"/></svg>
@else
    @php
        $ext = strtolower((string) ($ext ?? ''));
        $palette = [
            'pdf' => ['#dc2626', ['pdf']],
            'doc' => ['#2563eb', ['doc', 'docx', 'odt', 'rtf', 'dotx']],
            'xls' => ['#16a34a', ['xls', 'xlsx', 'ods', 'csv', 'xlsm']],
            'ppt' => ['#ea580c', ['ppt', 'pptx', 'odp']],
            'img' => ['#9333ea', ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'heic', 'bmp']],
            'zip' => ['#a16207', ['zip', '7z', 'rar', 'tar', 'gz']],
            'media' => ['#0891b2', ['mp3', 'wav', 'ogg', 'mp4', 'mov', 'avi', 'mkv', 'webm']],
            'txt' => ['#64748b', ['txt', 'md', 'log']],
        ];
        $group = 'other';
        $color = '#9ca3af';
        foreach ($palette as $name => [$hex, $list]) {
            if (in_array($ext, $list, true)) { $group = $name; $color = $hex; break; }
        }
        $label = $group === 'img' || $group === 'media' ? '' : strtoupper(substr($ext, 0, 4));
    @endphp
    <svg class="{{ $class ?? 'h-5 w-5' }} shrink-0" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M6 2h8l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#fff" stroke="{{ $color }}" stroke-width="1.4" stroke-linejoin="round"/>
        <path d="M14 2v5h5" fill="{{ $color }}" fill-opacity=".18" stroke="{{ $color }}" stroke-width="1.4" stroke-linejoin="round"/>
        @if($group === 'img')
            <circle cx="9.5" cy="12" r="1.3" fill="{{ $color }}"/><path d="m6 19 4-4.5 2.5 2.5 2-2L18 19z" fill="{{ $color }}"/>
        @elseif($group === 'media')
            <path d="m10 11 5 3.5-5 3.5z" fill="{{ $color }}"/>
        @elseif($group === 'zip')
            <path d="M10 8h2M10 10h2M10 12h2" stroke="{{ $color }}" stroke-width="1.2"/><rect x="9" y="14" width="4" height="4.5" rx=".8" fill="{{ $color }}"/>
        @elseif($label !== '')
            <rect x="4" y="13" width="15" height="6.5" fill="{{ $color }}"/><rect x="4" y="13" width="15" height="6.5" fill="none"/>
            <text x="11.5" y="18.2" text-anchor="middle" font-size="{{ strlen($label) > 3 ? 4.2 : 5 }}" font-weight="700" font-family="Arial, sans-serif" fill="#fff">{{ $label }}</text>
        @else
            <path d="M8 12h8M8 15h8M8 18h5" stroke="{{ $color }}" stroke-width="1.2" stroke-linecap="round"/>
        @endif
    </svg>
@endif
