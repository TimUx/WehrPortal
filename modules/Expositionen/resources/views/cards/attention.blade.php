<a href="{{ route($manage && ! $drafts && ! $prompts ? 'expositionen.manage' : 'expositionen.index') }}" class="block rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm hover:border-amber-300">
    <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Gefahrstoff-Exposition</p>
    @if($drafts)<p class="text-sm font-semibold text-amber-900">{{ $drafts }} Exposition{{ $drafts === 1 ? '' : 'en' }} bitte bestätigen</p>@endif
    @if($prompts)<p class="text-sm text-amber-900">Warst du beim letzten Brandeinsatz Gefahrstoffen ausgesetzt?</p>@endif
    @if($manage)<p class="text-sm text-amber-900">{{ $manage }} noch nicht an die ZED gemeldet</p>@endif
</a>
