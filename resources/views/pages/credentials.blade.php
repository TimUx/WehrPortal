<x-layouts.app title="Personalakte – Nachweise">
    <div class="max-w-lg mx-auto space-y-6">
        <h1 class="text-xl font-bold text-gray-900">Meine Personalakte</h1>
        @include('partials.personnel-file-nav')
        <livewire:profile.credentials />
    </div>
</x-layouts.app>
