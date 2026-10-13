{{-- Keys of a place, vehicle or Objekt, for other modules' pages: $column (place_id|vehicle_id|object_id), $id.
     Who has a key is shown to the Schlüsselwart only. --}}
@php($keysFor = \Modules\Schluessel\src\Models\Key::with('current.user')->where($column, $id)->where('status', '!=', 'retired')->orderBy('code')->get())
@if($keysFor->isNotEmpty())
    @php($keyManager = \Modules\Schluessel\src\Services\KeyService::canManage(auth()->user()))
    <section class="{{ $class ?? 'rounded-2xl border border-gray-100 bg-white p-4 shadow-sm' }} space-y-2">
        <h2 class="text-sm font-semibold text-gray-900">Schlüssel</h2>
        <ul class="divide-y divide-gray-100 text-sm">
            @foreach($keysFor as $k)
                <li class="flex flex-wrap items-center justify-between gap-2 py-1.5">
                    <span>@if($keyManager)<a href="{{ route('schluessel.show', $k) }}" class="font-medium text-gray-900 hover:underline">{{ $k->name }}</a>@else<span class="font-medium text-gray-900">{{ $k->name }}</span>@endif
                        <span class="text-gray-500">· {{ $k->code }} · {{ $k->kindLabel() }}</span></span>
                    <span class="text-xs {{ $k->status === 'lost' ? 'font-semibold text-red-700' : 'text-gray-500' }}">
                        @if($k->status === 'lost') Verloren
                        @elseif($k->current) Ausgegeben{{ $keyManager ? ' an '.$k->current->user->name : '' }}
                        @else {{ $k->storage ?: 'vorhanden' }}@endif
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
