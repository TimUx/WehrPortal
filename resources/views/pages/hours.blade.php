<x-layouts.app title="Personalakte – Stunden">
    <div class="max-w-lg mx-auto space-y-6">
        <h1 class="text-xl font-bold text-gray-900">Meine Personalakte</h1>
        @include('partials.personnel-file-nav')
        <livewire:profile.hours />
    </div>
</x-layouts.app>
