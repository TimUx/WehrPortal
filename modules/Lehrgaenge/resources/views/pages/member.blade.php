<x-layouts.app :title="$user->name">
    <livewire:lehrgaenge.member-profile :member="$user" :key="'member-'.$user->id" />
</x-layouts.app>
