@php($colors = ['ok' => 'text-green-700', 'soon' => 'text-amber-700', 'expired' => 'font-semibold text-red-700', 'missing' => 'font-semibold text-red-700'])
<div class="space-y-4">
    <h1 class="text-xl font-bold text-gray-900">Meine Unterweisungen</h1>
    @foreach($open as $a)
        <section class="space-y-2 rounded-2xl border border-amber-200 bg-amber-50 p-4" wire:key="o-{{ $a->id }}">
            <p class="text-sm text-amber-900">Bitte unterschreiben: <b>{{ $a->session->topic->name }}</b> am {{ $a->session->held_on->format('d.m.Y') }} ({{ $a->session->instructor_name }})</p>
            @if($signing === $a->id)
                @include('partials.signature-pad', ['model' => 'signature', 'label' => 'Ich habe an der Unterweisung teilgenommen und sie verstanden – '.auth()->user()->name])
                @error('signature')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <button type="button" wire:click="sign" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Unterschrift speichern</button>
            @else
                <button type="button" wire:click="$set('signing', {{ $a->id }})" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Jetzt unterschreiben</button>
            @endif
        </section>
    @endforeach
    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white text-sm shadow-sm">
        @forelse($rows as $r)
            <div class="flex flex-wrap items-center justify-between gap-2 p-3">
                <span><span class="font-medium text-gray-900">{{ $r['topic']->label() }}</span>@if($r['topic']->kind === 'einweisung')<span class="text-xs text-gray-500"> · Einweisung{{ $r['topic']->vehicle ? ' '.$r['topic']->vehicle->name : '' }}</span>@endif
                    @unless($r['required'])<span class="text-xs text-gray-400"> · freiwillig</span>@endunless</span>
                <span class="text-xs {{ $colors[$r['state']] }}">{{ \Modules\Unterweisungen\src\Services\InstructionService::STATES[$r['state']] }}{{ $r['last'] ? ' · zuletzt '.$r['last']->format('d.m.Y') : '' }}{{ $r['until'] ? ' · bis '.$r['until']->format('d.m.Y') : '' }}</span>
            </div>
        @empty
            <p class="p-4 text-gray-500">Für dich sind keine Unterweisungen vorgesehen.</p>
        @endforelse
    </div>
</div>
