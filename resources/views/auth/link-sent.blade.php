<x-layouts.app title="Link gesendet">
    <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-sm text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-2xl mb-4">
                <svg class="h-9 w-9 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <h1 class="text-xl font-semibold text-gray-900 mb-2">Link unterwegs!</h1>
            <p class="text-sm text-gray-500">
                Falls diese E-Mail-Adresse bei uns registriert ist, haben wir dir einen Login-Link geschickt.
                Der Link ist {{ config('magic-link.ttl_minutes') }} Minuten gültig.
            </p>
            <a href="{{ route('auth.magic-link.form') }}" class="mt-6 inline-block text-sm text-fw-red hover:underline">
                Zurück zur Anmeldung
            </a>
        </div>
    </div>
</x-layouts.app>
