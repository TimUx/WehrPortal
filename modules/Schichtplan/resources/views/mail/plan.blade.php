<x-mail::message>
# Hallo {{ $name }},

{{ $firstTime ? 'der Schichtplan steht' : 'der Schichtplan wurde aktualisiert' }}: **{{ $plan->title }}**{{ $plan->location ? ', '.$plan->location : '' }}
{{ $plan->timeLabel() }}

@if($leading->isNotEmpty())
**Du hast die Stationsverantwortung für: {{ $leading->implode(', ') }}** – über den ganzen Abend.

@endif
@if($mine->isEmpty())
Du bist in diesem Plan zurzeit für keine Schicht eingeteilt.
@else
**Deine Schichten**

@foreach($mine as $shift)
- {{ $shift['time'] }} Uhr: {{ $shift['station'] }}
@endforeach
@endif

<x-mail::table>
| Zeit | @foreach($stations as $station){{ $station }} | @endforeach

| :--- | @foreach($stations as $station):--- | @endforeach

@if(collect($leads)->contains(fn ($lead) => $lead !== '–'))
| Verantwortlich | @foreach($leads as $lead){{ $lead }} | @endforeach

@endif
@foreach($grid as $row)
| {{ $row['time'] }} | @foreach($row['cells'] as $cell){{ $cell }} | @endforeach

@endforeach
</x-mail::table>

<x-mail::button :url="$url">
Plan in der App ansehen
</x-mail::button>

{{ $salutation }}
</x-mail::message>
