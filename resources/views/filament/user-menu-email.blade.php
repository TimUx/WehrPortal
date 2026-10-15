{{-- User menu of the admin panel: the e-mail address in small under the name --}}
@if($email = auth()->user()?->email)
    <div style="padding: 0 1rem .5rem; margin-top: -.375rem; font-size: .75rem; line-height: 1rem; color: rgb(107 114 128); overflow-wrap: anywhere;">{{ $email }}</div>
@endif
