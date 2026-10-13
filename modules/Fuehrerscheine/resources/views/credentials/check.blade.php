@php($L = \Modules\Fuehrerscheine\src\Models\License::class)
<p style="font-weight:600;color:{{ $license->checkDue() ? '#92400e' : '#065f46' }}">{{ $license->checkDue() ? 'Führerscheinprüfung fällig' : 'Führerschein geprüft am '.$license->validated_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</p>
<dl><dt>Gültige Klassen</dt><dd>{{ collect($license->validClasses())->map(fn ($c) => $L::FIRE[$c] ?? $c)->implode(', ') ?: 'keine' }}</dd></dl>
