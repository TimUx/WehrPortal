{{-- Tabs: the Überwachungen for everyone, the Geräteträger for the Atemschutzwarte --}}
@php($manage = \Modules\Atemschutz\src\Services\MonitorService::canManage(auth()->user()))
<nav class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach(array_filter(['atemschutz.index' => 'Überwachung', 'atemschutz.wearers' => $manage ? 'Geräteträger' : null, 'atemschutz.personnel-file' => 'Mein Status']) as $route => $label)
        <a href="{{ route($route) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($route) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $label }}</a>
    @endforeach
</nav>
