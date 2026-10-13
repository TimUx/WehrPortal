<x-layouts.app title="Personalakte – Lehrgänge">
    <div class="max-w-2xl mx-auto space-y-6">
        <h1 class="text-xl font-bold text-gray-900">Meine Personalakte</h1>
        @include('partials.personnel-file-nav')
        @include('lehrgaenge::partials.member-summary', ['member' => auth()->user(), 'class' => 'space-y-3'])
        <a href="{{ route('lehrgaenge.index') }}" class="inline-block text-sm text-gray-600 underline">Interesse an einem Lehrgang melden →</a>
    </div>
</x-layouts.app>
