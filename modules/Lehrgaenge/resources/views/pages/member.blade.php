<x-layouts.app :title="$user->name">
    @include('lehrgaenge::partials.nav')
    <livewire:lehrgaenge.member-profile :member="$user" :key="'member-'.$user->id" />
</x-layouts.app>
