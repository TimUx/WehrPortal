@extends('errors.layout')
@section('title', 'Kein Zugriff')
@section('code', 'FEHLER 403')
@section('heading', 'Absperrung! Hier geht es nicht weiter.')
@section('message')
    <p>Für diesen Bereich fehlt dir die Berechtigung.</p>
    <p>Wenn du meinst, dass das ein Fehler ist, melde dich bei den Admins.</p>
@endsection
