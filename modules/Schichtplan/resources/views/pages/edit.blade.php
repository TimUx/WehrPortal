<x-layouts.app :title="$plan->title">
    <livewire:schichtplan.plan-editor :plan="$plan" :key="'plan-'.$plan->id" />
</x-layouts.app>
