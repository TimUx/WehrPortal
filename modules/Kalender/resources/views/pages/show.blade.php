<x-layouts.app :title="$event->title">
    <livewire:kalender.event-detail :event="$event" :key="'event-'.$event->id" />
    {{-- Schichtpläne of the event, when that module is on and the Kamerad may use it --}}
    @if(app(\App\Services\ModuleRegistry::class)->isActive('schichtplan') && auth()->user()->canUseModule('schichtplan'))
        @include('schichtplan::partials.event-section', ['event' => $event])
    @endif
</x-layouts.app>
