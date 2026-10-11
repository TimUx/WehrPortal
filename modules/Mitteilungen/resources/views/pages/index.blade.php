<x-layouts.app title="Mitteilungen">
    <div class="max-w-3xl mx-auto space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold text-gray-900">Mitteilungen</h1>
            @can('post-announcements')
                <a href="{{ route('mitteilungen.manage') }}" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Mitteilungen verwalten</a>
            @endcan
        </div>
        <p class="text-sm text-gray-500">Alle aktuellen Mitteilungen für dich, auch die auf der Startseite ausgeblendeten.</p>

        @forelse($announcements as $announcement)
            <article class="rounded-2xl border p-4 {{ $announcement->important ? 'border-red-300 bg-red-50' : 'border-gray-100 bg-white shadow-sm' }}">
                <p class="font-semibold {{ $announcement->important ? 'text-red-900' : 'text-gray-900' }}">
                    @if($announcement->important)<span class="mr-1 inline-block rounded bg-red-600 px-1.5 py-0.5 text-xs font-bold uppercase text-white">Wichtig</span>@endif
                    {{ $announcement->title }}
                </p>
                @if(filled($announcement->body))<p class="mt-1 text-sm whitespace-pre-line {{ $announcement->important ? 'text-red-900' : 'text-gray-700' }}">{{ $announcement->body }}</p>@endif
                <p class="mt-2 text-xs text-gray-500">
                    {{ $announcement->author?->name ?? 'Wehrführung' }} · {{ ($announcement->visible_from ?? $announcement->created_at)->setTimezone('Europe/Berlin')->format('d.m.Y') }}
                    @if($announcement->visible_until) · bis {{ $announcement->visible_until->setTimezone('Europe/Berlin')->format('d.m.Y') }}@endif
                    @if(in_array($announcement->id, $dismissed, true)) · auf der Startseite ausgeblendet @endif
                </p>
            </article>
        @empty
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">Zurzeit gibt es keine Mitteilungen.</div>
        @endforelse
    </div>
</x-layouts.app>
