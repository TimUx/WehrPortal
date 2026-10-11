{{-- Tabs: everyone sees the overview; Kassenwart and Wehrführung more --}}
@php($review = \Modules\Finanzen\src\Services\FinanceService::canReview(auth()->user()))
@php($manage = \Modules\Finanzen\src\Services\FinanceService::canManage(auth()->user()))
<nav class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach(array_filter(['finanzen.index' => 'Übersicht', 'finanzen.submit' => 'Ausgabe einreichen', 'finanzen.entries' => $review ? 'Buchungen' : null, 'finanzen.budget' => $manage ? 'Budget & Kostenstellen' : null, 'finanzen.report' => $review ? 'Jahresabschluss' : null]) as $route => $label)
        <a href="{{ route($route) }}" class="rounded-full px-3 py-1.5 {{ request()->routeIs($route) ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $label }}</a>
    @endforeach
</nav>
