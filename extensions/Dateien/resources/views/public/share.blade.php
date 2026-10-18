@extends('dateien::public.layout')

@php
    $size = function (?int $bytes): string {
        if ($bytes === null) return '';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) { $value /= 1024; $i++; }
        return number_format($value, $i === 0 ? 0 : 1, ',', '.').' '.$units[$i];
    };
@endphp

@section('content')
    <div class="card">
        <nav class="crumbs" aria-label="Pfad">
            @foreach ($path as $i => $node)
                @if ($i > 0) / @endif
                @if ($loop->last)
                    <strong>{{ $node->name }}</strong>
                @else
                    <a href="{{ route('dateien.share', ['token' => $token] + ($i === 0 ? [] : ['ordner' => $node->id])) }}">{{ $node->name }}</a>
                @endif
            @endforeach
        </nav>
        @if ($share->label)
            <p class="muted">{{ $share->label }}</p>
        @endif
        <p class="muted">
            Gültig bis {{ $share->expires_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr
            @if ($share->max_downloads)
                · noch {{ max(0, $share->max_downloads - $share->download_count) }} Downloads
            @endif
        </p>

        @if ($current->kind === 'folder')
            @if ($children->isEmpty())
                <p class="muted">Dieser Ordner ist leer.</p>
            @else
                <table>
                    <thead><tr><th>Name</th><th class="num">Größe</th><th class="num">Geändert</th></tr></thead>
                    <tbody>
                    @foreach ($children as $child)
                        <tr>
                            <td>
                                @if ($child->kind === 'folder')
                                    📁 <a href="{{ route('dateien.share', ['token' => $token, 'ordner' => $child->id]) }}">{{ $child->name }}</a>
                                @else
                                    📄 <a href="{{ route('dateien.share.download', ['token' => $token, 'node' => $child->id]) }}">{{ $child->name }}</a>
                                @endif
                            </td>
                            <td class="num muted">{{ $size($sizes[$child->id] ?? null) }}</td>
                            <td class="num muted">{{ $child->updated_at?->timezone('Europe/Berlin')->format('d.m.Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @else
            <p>{{ $current->name }} <span class="muted">{{ $size($sizes[$current->id] ?? null) }}</span></p>
            <a class="btn" href="{{ route('dateien.share.download', ['token' => $token, 'node' => $current->id]) }}">Herunterladen</a>
        @endif
    </div>
@endsection
