<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Support\Str;

/**
 * A notification that also appears under the bell next to "Profil". The entry is taken from the
 * mail: subject, first line and the button's link, so both always say the same. Mail to a bare
 * address (no Kamerad behind it) stays mail only. A mail built from its own template (no lines,
 * no button) defines inApp($notifiable) returning the text and the link instead.
 */
trait InApp
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    /** @return array{title: string, body: string, url: ?string, module: ?string} */
    public function toDatabase(object $notifiable): array
    {
        $mail = $this->toMail($notifiable);
        $module = self::moduleKey();
        $first = collect($mail->introLines)->map(fn ($line) => trim(str_replace(['**', '__'], '', strip_tags((string) $line))))->first(fn ($line) => $line !== '');

        $own = method_exists($this, 'inApp') ? $this->inApp($notifiable) : [];

        return [
            'title' => (string) $mail->subject,
            'body' => Str::limit((string) ($own['body'] ?? $first), 200),
            'url' => array_key_exists('url', $own) ? $own['url'] : ($mail->actionUrl ?: self::moduleUrl($module)),
            'module' => $module,
        ];
    }

    /** "Modules\Lehrgaenge\…" → lehrgaenge */
    private static function moduleKey(): ?string
    {
        return preg_match('/^Modules\\\\([^\\\\]+)\\\\/', static::class, $m) ? Str::lower($m[1]) : null;
    }

    private static function moduleUrl(?string $module): ?string
    {
        $route = $module ? app(ModuleRegistry::class)->get($module)['route'] ?? null : null;

        return match (true) {
            $route === null => null,
            str_starts_with($route, '/') => url($route),
            default => route($route),
        };
    }
}
