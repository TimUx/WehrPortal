<x-layouts.app :title="'Einsätze '.$user->name">
    <div class="space-y-4">
        <div>
            <a href="{{ route('einsatzbericht.members') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Kameraden</a>
            <h1 class="mt-1 text-xl font-bold text-gray-900">Einsätze: {{ $user->name }}</h1>
        </div>
        <livewire:einsatzbericht.member-missions :member="$user" :key="'member-'.$user->id" />
    </div>
</x-layouts.app>
