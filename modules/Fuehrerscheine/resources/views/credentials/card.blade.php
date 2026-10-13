{{-- Personalakte → Nachweise: the Fahrberechtigung --}}
@php($L = \Modules\Fuehrerscheine\src\Models\License::class)
<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
    <div class="flex items-center justify-between bg-gray-50 px-4 py-3"><span class="text-sm font-semibold text-gray-900">Fahrerlaubnis</span>
        <span class="text-sm font-semibold {{ $license->checkDue() ? 'text-amber-800' : 'text-green-700' }}">{{ $license->checkDue() ? 'Prüfung fällig' : 'geprüft' }}</span></div>
    <dl class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1 p-4 text-sm">
        @foreach($license->classes as $c)
            @php($ok = in_array($c['class'], $license->validClasses(), true))
            <dt class="text-gray-500">{{ $L::FIRE[$c['class']] ?? 'Klasse '.$c['class'] }}</dt><dd class="{{ $ok ? 'text-gray-900' : 'text-red-700' }}">{{ $c['valid_until'] ? ($ok ? 'bis ' : 'abgelaufen ').\Illuminate\Support\Carbon::parse($c['valid_until'])->format('d.m.Y') : 'unbefristet' }}</dd>
        @endforeach
        <dt class="text-gray-500">Geprüft</dt><dd>{{ $license->validated_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</dd>
    </dl>
</div>
