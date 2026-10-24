{{-- The saved places as suggestions for a free Ort field (list="wehrportal-places") --}}
<datalist id="wehrportal-places">
    @foreach(\App\Models\Place::options() as $name)<option value="{{ $name }}"></option>@endforeach
</datalist>
