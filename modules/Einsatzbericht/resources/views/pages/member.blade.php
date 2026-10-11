<x-layouts.app :title="'Einsätze '.$user->name">
    @include('einsatzbericht::partials.nav')
    <div class="space-y-4">
        <div>
            <a href="{{ route('einsatzbericht.members') }}" class="text-sm text-gray-500 hover:text-gray-900">← Kameraden</a>
            <h1 class="mt-1 text-xl font-bold text-gray-900">Einsätze: {{ $user->name }}</h1>
        </div>
        <livewire:einsatzbericht.member-missions :member="$user" :key="'member-'.$user->id" />
    </div>
</x-layouts.app>
