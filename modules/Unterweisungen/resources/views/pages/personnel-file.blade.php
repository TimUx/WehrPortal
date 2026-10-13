<x-layouts.app title="Personalakte – Unterweisungen">
    <div class="max-w-2xl mx-auto space-y-6">
        <h1 class="text-xl font-bold text-gray-900">Meine Personalakte</h1>
        @include('partials.personnel-file-nav')
        <livewire:unterweisungen.my-instructions />
    </div>
</x-layouts.app>
