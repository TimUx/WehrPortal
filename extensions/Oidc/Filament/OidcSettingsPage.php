<?php

namespace Extensions\Oidc\Filament;

use App\Models\User;
use Extensions\Oidc\Services\LockoutGuard;
use Extensions\Oidc\Services\OidcClient;
use Extensions\Oidc\Support\OidcSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class OidcSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'oidc';

    protected static ?string $navigationLabel = 'Single Sign-On (OIDC)';

    protected static ?string $title = 'Single Sign-On (OIDC)';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 6;

    protected string $view = 'oidc::filament.settings';

    /** @var array<string, mixed> The client secret is never filled in, only typed */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->is_admin ?? false;
    }

    public function mount(): void
    {
        $settings = app(OidcSettings::class);

        $this->form->fill([
            'enabled' => $settings->enabled(),
            'label' => $settings->label(),
            'issuer' => $settings->issuer(),
            'client_id' => $settings->clientId(),
            'scopes' => implode(' ', $settings->scopes()),
            'link_by_email' => $settings->linkByEmail(),
            'jit' => $settings->jit(),
            'exclusive' => $settings->exclusive(),
            'local_admins' => User::where('is_admin', true)->where('ext_local_admin', true)->pluck('id')->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(OidcSettings::class);

        return $schema
            ->components([
                Section::make('Identity Provider')
                    ->description('OpenID Connect (Authorization Code Flow mit PKCE), z. B. Keycloak. Anleitung: docs-extended/OIDC.md')
                    ->schema([
                        Forms\Components\Toggle::make('enabled')->label('Single Sign-On aktiv'),
                        Forms\Components\TextInput::make('label')->label('Text des Anmelde-Buttons')->maxLength(60),
                        Forms\Components\TextInput::make('issuer')
                            ->label('Issuer-URL')
                            ->placeholder('https://sso.example.com/realms/musterstadt')
                            ->helperText('Über diese Adresse wird /.well-known/openid-configuration abgerufen. Nur https.')
                            ->regex('/^https:\/\/\S+$/')
                            ->validationMessages(['regex' => 'Die Issuer-URL muss mit https:// beginnen.'])
                            ->maxLength(255),
                        Forms\Components\TextInput::make('client_id')->label('Client-ID')->maxLength(255),
                        Forms\Components\TextInput::make('client_secret')
                            ->label('Client-Secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->placeholder($settings->hasSecret() ? '•••••••• gespeichert – leer lassen, um es zu behalten' : '')
                            ->helperText($settings->hasSecret() ? 'Ein Secret ist gespeichert (verschlüsselt) und wird nie wieder angezeigt.' : 'Noch kein Secret gespeichert.'),
                        Forms\Components\Toggle::make('remove_secret')
                            ->label('Gespeichertes Secret entfernen')
                            ->visible(fn () => $settings->hasSecret()),
                        Forms\Components\TextInput::make('scopes')->label('Scopes')->helperText('Mit Leerzeichen getrennt; „openid“ ist immer dabei.')->maxLength(255),
                    ])
                    ->columnSpanFull(),

                Section::make('Benutzer-Zuordnung')
                    ->schema([
                        Forms\Components\Toggle::make('link_by_email')
                            ->label('Beim ersten Login bestehendes Konto über die bestätigte E-Mail-Adresse verknüpfen')
                            ->helperText('Nur mit email_verified = true. Danach zählt ausschließlich Issuer + Subject.'),
                        Forms\Components\Toggle::make('jit')
                            ->label('Neue Konten beim ersten Login automatisch anlegen')
                            ->helperText('Standard: aus. Neue Konten haben keine Admin-Rechte und keine Modulzugänge. Admin-Rechte kommen nie aus dem IdP.'),
                    ])
                    ->columnSpanFull(),

                Section::make('Lokale System-Admins und Notfall')
                    ->description('Lokale System-Admins können sich immer per Link-Mail anmelden, auch wenn der IdP ausfällt (Seite /anmelden/lokal).')
                    ->schema([
                        Forms\Components\Select::make('local_admins')
                            ->label('Lokale System-Admins')
                            ->multiple()
                            ->options(fn () => User::where('is_admin', true)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                        Forms\Components\Toggle::make('exclusive')
                            ->label('Nur Single Sign-On: Anmeldung per Link nur für lokale System-Admins')
                            ->helperText('Lässt sich nur speichern, wenn mindestens ein aktiver lokaler System-Admin existiert. Notfall per Konsole: php artisan oidc:disable'),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(OidcSettings::class);

        $secret = ! empty($data['remove_secret']) ? null : (filled($data['client_secret'] ?? null) ? $data['client_secret'] : $settings->secret());

        if (! empty($data['enabled']) && (blank($data['issuer'] ?? null) || blank($data['client_id'] ?? null) || blank($secret))) {
            throw ValidationException::withMessages(['data.issuer' => 'Zum Aktivieren sind Issuer-URL, Client-ID und Client-Secret nötig.']);
        }

        $localIds = array_map('intval', (array) ($data['local_admins'] ?? []));
        $localAdmins = User::whereKey($localIds)->where('is_admin', true)->where('is_active', true)->where('email', 'like', '%@%');
        if (! empty($data['exclusive']) && ! empty($data['enabled']) && $localAdmins->doesntExist()) {
            throw ValidationException::withMessages(['data.exclusive' => 'Mindestens ein aktiver lokaler System-Admin ist nötig, bevor die Anmeldung per Link abgeschaltet wird.']);
        }

        // Flags first (the lockout guard still sees the old settings), then the settings
        User::where('is_admin', true)->get()->each(function (User $admin) use ($localIds) {
            $local = in_array($admin->id, $localIds, true);
            if ($admin->ext_local_admin !== $local) {
                $admin->forceFill(['ext_local_admin' => $local])->save();
            }
        });

        $settings->set('enabled', ! empty($data['enabled']));
        $settings->set('label', $data['label'] ?? null);
        $settings->set('issuer', isset($data['issuer']) ? rtrim($data['issuer'], '/') : null);
        $settings->set('client_id', $data['client_id'] ?? null);
        $settings->set('scopes', $data['scopes'] ?? null);
        $settings->set('link_by_email', ! empty($data['link_by_email']));
        $settings->set('jit', ! empty($data['jit']));
        $settings->set('exclusive', ! empty($data['exclusive']));
        if (! empty($data['remove_secret']) || filled($data['client_secret'] ?? null)) {
            $settings->saveSecret($secret);
        }

        $this->data['client_secret'] = null;
        $this->data['remove_secret'] = false;

        Notification::make()->title('Single Sign-On gespeichert')->success()->send();
    }

    public function testConnection(): void
    {
        $result = app(OidcClient::class)->test(($this->data['issuer'] ?? null) ?: app(OidcSettings::class)->issuer());

        Notification::make()
            ->title($result['ok'] ? 'Verbindung erfolgreich' : 'Verbindung fehlgeschlagen')
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $settings = app(OidcSettings::class);

        return [
            'active' => $settings->isActive(),
            'configured' => $settings->isConfigured(),
            'force_disabled' => $settings->forceDisabled(),
            'redirect_uri' => $settings->redirectUri(),
            'local_admins' => LockoutGuard::localAdmins()->count(),
        ];
    }
}
