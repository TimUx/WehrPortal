<div class="space-y-2 text-center">
    <img src="{{ \App\Support\QrCode::dataUri($invite['url'], 240) }}" alt="QR-Code der Einladung" style="margin:0 auto;width:14rem;height:14rem">
    @if($invite['method'] === 'sso')
        <p class="text-sm text-gray-600">Mit dem Handy scannen – die Anmeldung öffnet den Identitätsanbieter mit vorausgefüllter E-Mail-Adresse.</p>
    @else
        <p class="text-sm text-gray-600">Mit dem Handy scannen – {{ $user->name }} ist angemeldet, ohne Passwort. Gilt bis {{ $invite['expires_at']->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr und nur einmal.</p>
    @endif
    <p class="break-all text-xs text-gray-500">{{ $invite['url'] }}</p>
</div>
