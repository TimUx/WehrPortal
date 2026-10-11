<x-layouts.app :title="$event->title">
    <livewire:kalender.event-detail :event="$event" :key="'event-'.$event->id" />
</x-layouts.app>
