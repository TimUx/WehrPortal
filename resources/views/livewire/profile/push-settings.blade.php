<div class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5" x-data="pushSettings(@js($publicKey))">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-gray-900">Push-Benachrichtigungen</p>
            <p class="text-xs text-gray-500">Alles, was unter der Glocke erscheint, kommt dann auch als Nachricht auf dieses Gerät – auch wenn die App geschlossen ist.</p>
        </div>
        <button type="button" role="switch" x-show="supported" :aria-checked="enabled" @click="toggle()" :disabled="busy || denied"
                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50" :class="enabled ? 'bg-fw-navy' : 'bg-gray-300'">
            <span class="sr-only">Push auf diesem Gerät</span>
            <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform" :class="enabled ? 'translate-x-5' : 'translate-x-0.5'"></span>
        </button>
    </div>
    <p x-show="!supported && ios && !standalone" x-cloak class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Auf dem iPhone kommt Push nur in der installierten App: im Safari auf „Teilen“ → „Zum Home-Bildschirm“ tippen, die App von dort öffnen und hier einschalten.</p>
    <p x-show="!supported && !(ios && !standalone)" x-cloak class="text-xs text-gray-500">Dieser Browser kann keine Push-Benachrichtigungen.</p>
    <p x-show="denied" x-cloak class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Benachrichtigungen sind für diese Seite im Browser blockiert. In den Einstellungen des Browsers erlauben, dann hier einschalten.</p>
    @error('push')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
    @if($flash)<p class="text-xs text-green-700">{{ $flash }}</p>@endif

    @if($devices->isNotEmpty())
        <ul class="divide-y divide-gray-100 text-sm">
            @foreach($devices as $device)
                <li class="flex items-center justify-between gap-2 py-1.5" wire:key="push-{{ $device->id }}">
                    <span class="text-gray-700">{{ $device->device ?? 'Gerät' }} <span class="text-xs text-gray-400">seit {{ $device->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</span></span>
                    <button type="button" wire:click="remove({{ $device->id }})" class="text-xs text-red-600 underline">Entfernen</button>
                </li>
            @endforeach
        </ul>
        <button type="button" wire:click="test" class="text-xs text-fw-navy underline">Test-Nachricht schicken</button>
    @endif
    <p class="text-xs text-gray-500">Tipp: WehrPortal lässt sich wie eine App auf den Startbildschirm legen – im Browser-Menü „App installieren“ bzw. „Zum Home-Bildschirm“.</p>
</div>
