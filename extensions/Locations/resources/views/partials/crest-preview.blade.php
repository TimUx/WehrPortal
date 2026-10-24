@if($getRecord()?->crestUrl())
    <img src="{{ $getRecord()->crestUrl() }}" alt="Wappen {{ $getRecord()->name }}" style="height: 4rem; max-width: 8rem; object-fit: contain;">
@endif
