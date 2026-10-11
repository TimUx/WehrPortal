<x-layouts.app title="E-Mail-Adresse bestätigt">
    <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-sm">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 text-center">
                <h1 class="text-xl font-semibold text-gray-900 mb-2">Bestätigt</h1>
                <p class="text-sm text-gray-500 mb-6">
                    Deine Adresse für die Anmeldung ist jetzt <span class="font-semibold text-gray-900">{{ $email }}</span>. Login-Links gehen ab sofort dorthin.
                </p>
                <a href="{{ route('auth.magic-link.form') }}"
                   class="inline-block w-full bg-fw-red text-white font-semibold py-3 px-4 rounded-xl hover:bg-fw-red-dark transition-colors">
                    Zur Anmeldung
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>
