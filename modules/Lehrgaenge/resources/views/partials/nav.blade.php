{{-- On phones the top menu is hidden: links for Ausbildungsleiter and Wehrführung --}}
@if(auth()->user()->canViewLehrgaenge())
    <nav class="sm:hidden mb-4 flex flex-wrap gap-2 text-sm">
        @foreach(array_filter(['lehrgaenge.index' => 'Meine Lehrgänge', 'lehrgaenge.registrations' => auth()->user()->canManageLehrgaenge() ? 'Anmeldungen' : null, 'lehrgaenge.courses' => auth()->user()->canManageLehrgaenge() ? 'Lehrgangsliste' : null, 'lehrgaenge.members' => 'Kameraden']) as $route => $label)
            <a href="{{ route($route) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($route) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700' }}">{{ $label }}</a>
        @endforeach
    </nav>
@endif
