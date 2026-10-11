@extends('errors.layout')
@section('title', 'Fehler')
@section('code', 'FEHLER 500')
@section('heading', 'Einsatz! Bei uns brennt gerade etwas.')
@section('message')
    <p>Da ist leider etwas schiefgelaufen.</p>
    <p>Die Admins sind informiert – versuche es bitte gleich noch einmal.</p>
@endsection
