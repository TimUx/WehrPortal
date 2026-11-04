@include('partials.signature-pad', [
    'model' => $getStatePath(),
    'label' => 'Prüfer: '.(auth()->user()?->name ?? 'Prüfer'),
])
