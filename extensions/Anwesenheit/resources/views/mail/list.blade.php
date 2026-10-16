<p>Hallo,</p>
<p>die Anwesenheitsliste „{{ $list->title }}“ ({{ $list->kindLabel() }}) wurde abgeschlossen.</p>
<ul>
    <li>Beginn: {{ $list->starts_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr, Dauer: {{ $list->durationLabel() }}</li>
    <li>Leitung: {{ $list->leader?->name ?? '–' }}</li>
    <li>Teilnehmer: {{ $list->participant_count }}</li>
</ul>
<p>Die Liste hängt als PDF an{{ $list->files->isNotEmpty() ? ', ebenso die hochgeladenen Dateien' : '' }}.</p>
<p>{{ $org->salutation() }}</p>
