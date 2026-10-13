<?php

namespace App\Services;

use App\Models\User;

/**
 * Digitale Nachweise on the phone (Personalakte → Nachweise): modules add a card, e.g. the
 * Dienstausweis (Personalverwaltung) or the Atemschutzpass (Atemschutz). The same modules render
 * what a checker sees after scanning the short-lived QR code.
 */
class CredentialRegistry
{
    /** @var array<string, array{module: string, title: string, card: callable, verify: callable}> */
    private array $items = [];

    /**
     * @param  callable(User): ?string  $card  the card for the Kamerad, null when there is none
     * @param  callable(User): ?string  $verify  what the checker sees, null when there is nothing to show
     */
    public function register(string $module, string $title, callable $card, callable $verify): void
    {
        $this->items[$module.'.'.$title] = compact('module', 'title', 'card', 'verify');
    }

    /** @return list<array{title: string, html: string}> */
    public function cards(User $user): array
    {
        return $this->render($user, 'card', fn ($item) => $user->canUseModule($item['module']));
    }

    /** @return list<array{title: string, html: string}> for the checker: only while the module is on */
    public function verify(User $user): array
    {
        $registry = app(ModuleRegistry::class);

        return $this->render($user, 'verify', fn ($item) => $registry->isActive($item['module']));
    }

    private function render(User $user, string $key, callable $allowed): array
    {
        return collect($this->items)->filter($allowed)
            ->map(fn ($item) => ['title' => $item['title'], 'html' => ($item[$key])($user)])
            ->filter(fn ($item) => filled($item['html']))->values()->all();
    }
}
