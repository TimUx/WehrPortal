<dl>
    @if($id['user']->rank)<dt>Dienstgrad</dt><dd>{{ $id['user']->rank }}</dd>@endif
    @if($id['user']->groups->isNotEmpty())<dt>Abteilung</dt><dd>{{ $id['user']->groups->pluck('name')->implode(', ') }}</dd>@endif
    @if($id['number'])<dt>Ausweis-Nr.</dt><dd>{{ $id['number'] }}</dd>@endif
    <dt>Gültig bis</dt><dd style="{{ $id['expired'] ? 'color:#991b1b;font-weight:600' : '' }}">{{ $id['valid_until'] ? ($id['expired'] ? 'abgelaufen am ' : '').$id['valid_until']->format('d.m.Y') : 'ohne Ablaufdatum' }}</dd>
</dl>
