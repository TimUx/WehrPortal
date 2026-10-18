@extends('dateien::public.layout')

@section('content')
    <div class="card">
        <h1>Passwortgeschützte Freigabe</h1>
        <p class="muted">Bitte das Passwort eingeben, das du zusammen mit dem Link erhalten hast.</p>
        @if ($error)
            <p class="error" role="alert">{{ $error }}</p>
        @endif
        <form method="POST" action="{{ route('dateien.share.unlock', ['token' => $token]) }}">
            @csrf
            <label for="passwort">Passwort</label>
            <input id="passwort" name="passwort" type="password" required autofocus autocomplete="off">
            <button class="btn" type="submit">Öffnen</button>
        </form>
    </div>
@endsection
