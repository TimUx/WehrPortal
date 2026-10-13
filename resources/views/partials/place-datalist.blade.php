{{-- The saved places as suggestions for a free Ort field (list="openwehr-places") --}}
<datalist id="openwehr-places">
    @foreach(\App\Models\Place::options() as $name)<option value="{{ $name }}"></option>@endforeach
</datalist>
