{{-- Tabs: the list (all entries for the managers, the own for everyone else) and a new entry --}}
@php($manage = \Modules\Verbandbuch\src\Services\VerbandbuchService::canManage(auth()->user()))
<nav class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach(['verbandbuch.index' => $manage ? 'Verbandbuch' : 'Meine Einträge', 'verbandbuch.create' => 'Eintrag erfassen'] as $route => $label)
        <a href="{{ route($route) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($route) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $label }}</a>
    @endforeach
</nav>
