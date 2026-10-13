{{-- Personalverwaltung: the Führerschein of $member --}}
@php($fs = \Modules\Fuehrerscheine\src\Models\License::where('user_id', $member->id)->first())
<section class="space-y-1 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
    <h2 class="text-sm font-semibold text-gray-900">Führerschein</h2>
    @if(! $fs)
        <p class="text-sm text-gray-500">Nicht hinterlegt.</p>
    @else
        <p class="text-sm {{ $fs->usable() && ! $fs->checkDue() ? 'text-gray-800' : 'text-amber-800' }}">{{ $fs->statusLabel() }}{{ $fs->validClasses() ? ' · '.collect($fs->validClasses())->map(fn ($c) => \Modules\Fuehrerscheine\src\Models\License::FIRE[$c] ?? $c)->implode(', ') : '' }}</p>
    @endif
</section>
