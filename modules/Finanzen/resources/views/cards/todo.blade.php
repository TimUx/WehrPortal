<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Finanzen</p>
    <ul class="mt-1 space-y-0.5 text-sm text-gray-700">
        @if($todo['review'] > 0)<li class="font-semibold text-amber-800">{{ $todo['review'] }} Ausgabe{{ $todo['review'] === 1 ? '' : 'n' }} zu genehmigen</li>@endif
        @if($todo['pay'] > 0)<li>{{ $todo['pay'] }} genehmigte Ausgabe{{ $todo['pay'] === 1 ? '' : 'n' }} noch zu bezahlen</li>@endif
        @if($todo['own'] > 0)<li>{{ $todo['own'] }} eigene Ausgabe{{ $todo['own'] === 1 ? '' : 'n' }} in Bearbeitung</li>@endif
    </ul>
    <a href="{{ $todo['review'] > 0 ? route('finanzen.entries', ['status' => 'submitted']) : route('finanzen.index') }}" class="text-sm text-fw-navy underline">Budget & Finanzen</a>
</div>
