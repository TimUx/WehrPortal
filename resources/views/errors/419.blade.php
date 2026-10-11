@extends('errors.layout')
@section('title', 'Sitzung abgelaufen')
@section('code', 'FEHLER 419')
@section('heading', 'Die Seite war zu lange offen.')
@section('message')
    <p>Deine Sitzung ist abgelaufen. Lade die Seite neu und versuche es noch einmal.</p>
@endsection
