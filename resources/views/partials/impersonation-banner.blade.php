{{-- An admin sees the app as a Kamerad: always visible, with the way back --}}
@if(session()->has(\App\Services\Impersonation::SESSION_KEY) && auth()->check())
    <div class="bg-fw-orange text-white text-sm" style="background:#F66123;color:#fff;font-size:.875rem">
        <div class="w-full px-4 sm:px-6 lg:px-8 2xl:px-12 py-2 flex flex-wrap items-center justify-between gap-2" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;padding:.5rem 1rem">
            <span>Du siehst die App als <strong>{{ auth()->user()->name }}</strong>.</span>
            <form method="POST" action="{{ route('impersonation.stop') }}">
                @csrf
                <button type="submit" class="rounded-lg bg-white px-3 py-1 font-semibold text-fw-navy hover:bg-gray-100" style="background:#fff;color:#1B2645;border-radius:.5rem;padding:.25rem .75rem;font-weight:600">Zurück zum Admin</button>
            </form>
        </div>
    </div>
@endif
