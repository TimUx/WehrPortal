<x-layouts.app title="E-Mail-Adresse bestätigen">
    <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-sm">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 text-center">
                <h1 class="text-xl font-semibold text-gray-900 mb-2">E-Mail-Adresse bestätigen</h1>

                @if($error)
                    <p class="text-sm text-red-700 mb-6">{{ $error }}</p>
                    <a href="{{ route('auth.magic-link.form') }}" class="inline-block text-sm text-gray-500 hover:text-gray-700">Zur Anmeldung</a>
                @else
                    <p class="text-sm text-gray-500 mb-6">
                        Soll <span class="font-semibold text-gray-900">{{ $newEmail }}</span> ab jetzt deine Adresse für die Anmeldung sein?
                    </p>

                    <form method="POST" action="{{ route('auth.email-change.consume', ['token' => $token]) }}">
                        @csrf
                        <button type="submit"
                                class="w-full bg-fw-red text-white font-semibold py-3 px-4 rounded-xl hover:bg-fw-red-dark transition-colors">
                            Ja, bestätigen
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
