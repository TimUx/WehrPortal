@if($errors->any())
    <div role="alert" class="rounded-lg bg-red-50 p-3 text-red-800">
        <ul class="list-inside list-disc">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif
