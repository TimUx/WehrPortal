<a href="{{ route($own ? 'unterweisungen.index' : 'unterweisungen.overview') }}" class="block rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm hover:border-amber-300">
    <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Unterweisungen</p>
    @foreach($own as $line)<p class="text-sm font-semibold text-amber-900">{{ $line }}</p>@endforeach
    @if($manage)<p class="text-sm text-amber-900">{{ $manage }} Kameraden mit fälliger oder fehlender Unterweisung</p>@endif
</a>
