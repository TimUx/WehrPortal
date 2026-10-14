<x-layouts.app title="Personalakte – Lehrgänge">
    <div class="max-w-2xl mx-auto space-y-6">
        @include('lehrgaenge::partials.member-summary', ['member' => auth()->user(), 'class' => 'space-y-3'])
        <a href="{{ route('lehrgaenge.index') }}" class="inline-block text-sm text-gray-600 underline">Interesse an einem Lehrgang melden →</a>
    </div>
</x-layouts.app>
