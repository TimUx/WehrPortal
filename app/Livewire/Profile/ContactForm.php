<?php

namespace App\Livewire\Profile;

use App\Models\User;
use App\Services\EmailChangeService;
use App\Support\MemberFields;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/** Profile: Kameraden change their own contact data; a new e-mail address needs confirmation first (double opt-in) */
class ContactForm extends Component
{
    public string $email = '';

    public string $phone = '';

    public string $birthDate = '';

    public bool $showBirthday = true;

    public bool $saved = false;

    /** The new address a confirmation mail went to, until it is confirmed */
    public ?string $pendingEmail = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
        $this->birthDate = (string) $user->birth_date?->format('d.m.Y');
        $this->showBirthday = (bool) ($user->show_birthday ?? true);
        $this->pendingEmail = app(EmailChangeService::class)->pending($user)?->new_email;
    }

    public function save(EmailChangeService $emailChange): void
    {
        $user = auth()->user();

        $this->email = trim($this->email);
        $this->phone = trim($this->phone);

        $this->validate(array_filter([
            'email' => [
                'required', 'email', 'max:255',
                // Addresses are compared without regard to case so nobody ends up with a look-alike of someone else's
                fn ($attribute, $value, $fail) => User::whereRaw('lower(email) = ?', [mb_strtolower($value)])
                    ->whereKeyNot($user->id)->exists() ? $fail('Diese E-Mail-Adresse wird schon von jemand anderem verwendet.') : null,
            ],
            'phone' => MemberFields::PHONE ? ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()\/.]+$/'] : null,
            'birthDate' => MemberFields::BIRTH_DATE ? ['nullable', 'date_format:d.m.Y', function ($attribute, $value, $fail) {
                $date = Carbon::createFromFormat('!d.m.Y', $value);
                if ($date->isFuture()) {
                    $fail('Das Geburtsdatum liegt in der Zukunft.');
                } elseif ($date->year < 1900) {
                    $fail('Bitte gib ein gültiges Geburtsdatum an.');
                }
            }] : null,
        ]), [
            'email.required' => 'Bitte gib deine E-Mail-Adresse ein.',
            'email.email' => 'Bitte gib eine gültige E-Mail-Adresse ein.',
            'birthDate.date_format' => 'Bitte im Format TT.MM.JJJJ eingeben, z. B. 17.05.1990.',
            'phone.regex' => 'Bitte gib eine gültige Mobilnummer ein (Ziffern, +, -, Leerzeichen, Klammern).',
        ]);

        // Only the fields shown are saved, so stored values of hidden ones stay
        $user->update(array_merge(
            MemberFields::PHONE ? ['phone' => $this->phone ?: null] : [],
            MemberFields::BIRTH_DATE ? [
                'birth_date' => $this->birthDate ? Carbon::createFromFormat('!d.m.Y', $this->birthDate)->toDateString() : null,
                'show_birthday' => $this->showBirthday,
            ] : [],
        ));

        $this->requestEmailChange($user, $emailChange);

        $this->saved = true;
    }

    /** Only a real change counts: the login address stays until the new one is confirmed */
    private function requestEmailChange(User $user, EmailChangeService $emailChange): void
    {
        if ($this->email === $user->email) {
            return;
        }

        // Only upper/lower case differs: same mailbox, nothing to confirm
        if (mb_strtolower($this->email) === mb_strtolower($user->email)) {
            $user->update(['email' => $this->email]);

            return;
        }

        try {
            $this->pendingEmail = $emailChange->request($user, $this->email)->new_email;
        } catch (ValidationException $e) {
            $this->addError('email', $e->errors()['email'][0]);
        }

        // The field keeps showing the address that is valid today
        $this->email = $user->email;
    }

    public function resend(EmailChangeService $emailChange): void
    {
        $pending = $emailChange->pending(auth()->user());

        if ($pending) {
            try {
                $emailChange->request(auth()->user(), $pending->new_email);
            } catch (ValidationException $e) {
                $this->addError('email', $e->errors()['email'][0]);
            }
        }
    }

    public function cancelEmailChange(EmailChangeService $emailChange): void
    {
        $emailChange->cancel(auth()->user());
        $this->pendingEmail = null;
    }

    public function render()
    {
        return view('livewire.profile.contact-form');
    }
}
