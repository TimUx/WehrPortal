<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\AvatarService;
use App\Services\OrganizationSettings;
use App\Services\ServiceSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class OrganizationSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'einstellungen';

    protected static ?string $navigationLabel = 'Einstellungen';

    protected static ?string $title = 'Einstellungen';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.organization-settings';

    public string $org_name = '';

    public string $org_short_name = '';

    public string $org_salutation = '';

    public string $org_code_prefix = '';

    /** @var array<int, TemporaryUploadedFile>|null */
    public ?array $logo = [];

    /** @var array<string, mixed> Dienste: API keys (never sent to the browser) and the AI model */
    public ?array $servicesData = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->is_admin ?? false;
    }

    public function mount(): void
    {
        $org = app(OrganizationSettings::class);

        $this->form->fill([
            'org_name' => $org->name(),
            'org_short_name' => $org->shortName(),
            'org_salutation' => $org->salutation(),
            'org_code_prefix' => $org->codePrefix(),
        ]);

        // The saved keys are never sent to the browser, only the model
        $this->servicesForm->fill(['openrouter_model' => app(ServiceSettings::class)->aiModel()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Wehrname')
                    ->schema([
                        Forms\Components\TextInput::make('org_name')
                            ->label('Vollständiger Name')
                            ->placeholder('Freiwillige Feuerwehr Musterstadt')
                            ->helperText('Wird in Mails und im Admin-Bereich angezeigt.')
                            ->maxLength(150),

                        Forms\Components\TextInput::make('org_short_name')
                            ->label('Kurzname')
                            ->placeholder('FF Musterstadt')
                            ->helperText('Kompakte Darstellung, z. B. in der Navigation.')
                            ->maxLength(60),

                        Forms\Components\TextInput::make('org_salutation')
                            ->label('Mail-Signatur')
                            ->placeholder('Deine Freiwillige Feuerwehr Musterstadt')
                            ->helperText('Schlusszeile in allen ausgehenden Mails.')
                            ->maxLength(200),
                    ])
                    ->columnSpanFull(),

                Section::make('Kleiderkammer')
                    ->schema([
                        Forms\Components\TextInput::make('org_code_prefix')
                            ->label('Code-Präfix')
                            ->placeholder('WEHR')
                            ->helperText('Wird jedem Artikelcode vorangestellt, z. B. „WEHREJA0001". Nur Groß-Buchstaben und Ziffern (2–6 Zeichen). Änderungen wirken sich nur auf neue Codes aus.')
                            ->maxLength(6)
                            ->minLength(2)
                            ->regex('/^[A-Z0-9]+$/')
                            ->validationMessages([
                                'regex' => 'Nur Großbuchstaben (A–Z) und Ziffern (0–9) erlaubt.',
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Logo')
                    ->schema([
                        Forms\Components\FileUpload::make('logo')
                            ->label('Logo hochladen')
                            ->image()
                            ->imageEditor()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
                            ->helperText('PNG, JPEG, WebP oder SVG. Wird im Header und auf dem Wachen-Monitor angezeigt.')
                            ->nullable(),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('');
    }

    public function save(AvatarService $avatarService): void
    {
        $data = $this->form->getState();
        $org = app(OrganizationSettings::class);

        Setting::set('org_name', $data['org_name'] ?: null);
        Setting::set('org_short_name', $data['org_short_name'] ?: null);
        Setting::set('org_salutation', $data['org_salutation'] ?: null);
        Setting::set('org_code_prefix', $data['org_code_prefix'] ?: null);

        if (! empty($data['logo']) && is_array($data['logo'])) {
            $file = reset($data['logo']);
            if ($file instanceof TemporaryUploadedFile) {
                $mime = $file->getMimeType() ?? 'image/webp';
                $contents = file_get_contents($file->getRealPath());
                $base64 = base64_encode($contents);
                $org->saveLogo($base64, $mime);
            }
        }

        Notification::make()->title('Einstellungen gespeichert')->success()->send();
    }

    public function deleteLogo(): void
    {
        app(OrganizationSettings::class)->deleteLogo();
        Notification::make()->title('Logo gelöscht')->success()->send();
    }

    // ─── Dienste (Google Maps, OpenRouter) ─────────────────────────────────

    public function servicesForm(Schema $schema): Schema
    {
        $services = app(ServiceSettings::class);

        return $schema
            ->components([
                Section::make('Google Maps')
                    ->description('Für Karten und Adressfunktionen. Den Schlüssel legst du in der Google Cloud Console an (Geocoding- und Maps-JavaScript-API aktivieren).')
                    ->schema([
                        Forms\Components\TextInput::make('google_maps_key')
                            ->label('API-Schlüssel')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->placeholder($services->googleMapsKey() ? '•••••••• gespeichert – leer lassen, um ihn zu behalten' : 'AIza…')
                            ->helperText($services->googleMapsKey() ? 'Ein Schlüssel ist gespeichert (verschlüsselt). Zum Ersetzen einen neuen eingeben.' : 'Noch kein Schlüssel gespeichert.'),
                        Forms\Components\Toggle::make('remove_google_maps_key')
                            ->label('Gespeicherten Schlüssel entfernen')
                            ->visible(fn () => (bool) $services->googleMapsKey()),
                    ])
                    ->columnSpanFull(),

                Section::make('KI über OpenRouter')
                    ->description('OpenRouter gibt Zugang zu vielen KI-Modellen mit einem Schlüssel. Konto und Schlüssel unter openrouter.ai.')
                    ->schema([
                        Forms\Components\TextInput::make('openrouter_key')
                            ->label('API-Schlüssel')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->placeholder($services->openRouterKey() ? '•••••••• gespeichert – leer lassen, um ihn zu behalten' : 'sk-or-…')
                            ->helperText($services->openRouterKey() ? 'Ein Schlüssel ist gespeichert (verschlüsselt). Zum Ersetzen einen neuen eingeben.' : 'Noch kein Schlüssel gespeichert.'),
                        Forms\Components\Toggle::make('remove_openrouter_key')
                            ->label('Gespeicherten Schlüssel entfernen')
                            ->visible(fn () => (bool) $services->openRouterKey()),
                        Forms\Components\Select::make('openrouter_model')
                            ->label('Modell')
                            ->options(fn () => $services->aiModels())
                            ->searchable()
                            ->placeholder('Modell wählen')
                            ->helperText('Die Liste kommt von OpenRouter und ist nach Namen sortiert; Preise stehen bei OpenRouter.'),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('servicesData');
    }

    public function saveServices(): void
    {
        $data = $this->servicesForm->getState();
        $services = app(ServiceSettings::class);

        if (! empty($data['remove_google_maps_key'])) {
            $services->saveGoogleMapsKey(null);
        } elseif (filled($data['google_maps_key'] ?? null)) {
            $services->saveGoogleMapsKey($data['google_maps_key']);
        }

        if (! empty($data['remove_openrouter_key'])) {
            $services->saveOpenRouterKey(null);
        } elseif (filled($data['openrouter_key'] ?? null)) {
            $services->saveOpenRouterKey($data['openrouter_key']);
        }

        $services->saveAiModel($data['openrouter_model'] ?? null);

        $this->servicesForm->fill(['openrouter_model' => $services->aiModel()]);

        Notification::make()->title('Dienste gespeichert')->success()->send();
    }

    /** Tests the key typed in the form, otherwise the saved one */
    public function testGoogleMaps(): void
    {
        $key = $this->servicesData['google_maps_key'] ?? null ?: app(ServiceSettings::class)->googleMapsKey();

        $this->report($key, fn (string $key) => app(ServiceSettings::class)->testGoogleMaps($key));
    }

    public function testOpenRouter(): void
    {
        $key = $this->servicesData['openrouter_key'] ?? null ?: app(ServiceSettings::class)->openRouterKey();

        $this->report($key, fn (string $key) => app(ServiceSettings::class)->testOpenRouter($key));
    }

    private function report(?string $key, callable $test): void
    {
        if (blank($key)) {
            Notification::make()->title('Kein Schlüssel vorhanden')->warning()->send();

            return;
        }

        $result = $test($key);

        Notification::make()
            ->title($result['ok'] ? 'Verbindung erfolgreich' : 'Verbindung fehlgeschlagen')
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }
}
