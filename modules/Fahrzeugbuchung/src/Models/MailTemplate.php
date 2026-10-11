<?php

namespace Modules\Fahrzeugbuchung\src\Models;

use App\Services\OrganizationSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A mail text the admins can edit. Placeholders like {name} are replaced when the mail is built.
 */
class MailTemplate extends Model
{
    public const BOOKING_END_REMINDER = 'booking_end_reminder';

    protected $fillable = [
        'key',
        'name',
        'subject',
        'greeting',
        'body',
        'action_label',
        'salutation',
    ];

    /** Default texts per template; "Standard wiederherstellen" in the admin goes back to these */
    public static function defaults(): array
    {
        return [
            self::BOOKING_END_REMINDER => [
                'name' => 'Erinnerung: Buchung endet bald',
                'subject' => 'Erinnerung: Deine Buchung von {fahrzeug} endet um {ende_uhrzeit} Uhr',
                'greeting' => 'Hallo {name},',
                'body' => "deine Reservierung für **{fahrzeug}** („{zweck}“) endet in {restzeit}, um **{ende_uhrzeit} Uhr**. Danach ist das Fahrzeug wieder für andere Kameraden buchbar.\n\n"
                    ."Bitte stelle sicher, dass das Fahrzeug rechtzeitig wieder zurück ist.\n\n"
                    .'Ist das Fahrzeug schon zurück? Dann melde es bitte über den Button zurück – so ist es schon früher für andere frei.',
                'action_label' => 'Fahrzeug ist schon zurück',
                'salutation' => app(OrganizationSettings::class)->salutation(),
            ],
        ];
    }

    /** Placeholders available per template, with a description for the admin form */
    public static function placeholders(string $key): array
    {
        return match ($key) {
            self::BOOKING_END_REMINDER => [
                'name' => 'Name der buchenden Person',
                'fahrzeug' => 'Fahrzeugname',
                'zweck' => 'Zweck der Buchung',
                'beginn' => 'Beginn mit Datum und Uhrzeit',
                'ende' => 'Ende mit Datum und Uhrzeit',
                'ende_uhrzeit' => 'Uhrzeit des Endes, z. B. 14:00',
                'restzeit' => 'Zeit bis zum Ende, z. B. „einer Stunde" oder „40 Minuten"',
            ],
            default => [],
        };
    }

    public static function for(string $key): self
    {
        return static::firstOrCreate(['key' => $key], static::defaults()[$key]);
    }

    public static function ensureDefaults(): void
    {
        foreach (array_keys(static::defaults()) as $key) {
            static::for($key);
        }
    }

    public function resetToDefault(): void
    {
        $this->update(static::defaults()[$this->key]);
    }

    /** Builds the mail; paragraphs in the body are separated by blank lines */
    public function toMailMessage(array $values, ?string $actionUrl = null): MailMessage
    {
        $replace = fn (?string $text) => strtr((string) $text, collect($values)->mapWithKeys(fn ($value, $name) => ['{'.$name.'}' => $value])->all());

        $mail = (new MailMessage)->subject($replace($this->subject));

        if (filled($this->greeting)) {
            $mail->greeting($replace($this->greeting));
        }

        foreach (preg_split('/\R\s*\R/', trim($replace($this->body))) as $paragraph) {
            $mail->line(trim($paragraph));
        }

        if ($actionUrl && filled($this->action_label)) {
            $mail->action($replace($this->action_label), $actionUrl);
        }

        if (filled($this->salutation)) {
            $mail->salutation($replace($this->salutation));
        }

        return $mail;
    }
}
