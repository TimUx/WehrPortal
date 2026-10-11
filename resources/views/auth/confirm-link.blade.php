<x-layouts.app title="Anmeldung bestätigen">
    <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-sm">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-red-100 rounded-2xl mb-4">
                    <svg class="h-9 w-9 text-fw-red" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <h1 class="text-xl font-semibold text-gray-900 mb-2">Jetzt anmelden</h1>
                <p class="text-sm text-gray-500 mb-6">
                    Klicke auf den Button, um dich anzumelden.
                </p>

                <form method="POST" action="{{ route('auth.magic-link.consume', ['token' => $token]) }}">
                    @csrf
                    <button type="submit"
                            class="w-full bg-fw-red text-white font-semibold py-3 px-4 rounded-xl hover:bg-fw-red-dark transition-colors">
                        Anmelden
                    </button>
                </form>

                <a href="{{ route('auth.magic-link.form') }}" class="mt-4 inline-block text-sm text-gray-500 hover:text-gray-700">
                    Neuen Link anfordern
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>
