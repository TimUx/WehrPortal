<?php

namespace Extensions\Mitteilungen\Filament;

use App\Models\User;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Mitteilungen\Services\ChannelSettings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ChannelsSettingsPage extends Page
{
    protected static ?string $slug = 'mitteilungen/kanale';

    protected static ?string $title = 'Mitteilungen-Kanäle';

    protected static ?string $navigationLabel = 'Externe Kanäle';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|\UnitEnum|null $navigationGroup = 'Mitteilungen';

    protected static ?int $navigationSort = 10;

    protected string $view = 'mitteilungen-channels::filament.settings';

    /** @var array<string, array<string, array<string, mixed>>> */
    public array $data = [];

    /** @var array<string, string> */
    public array $scopeLabels = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->is_admin
            || (config('locations.enabled') && app()->bound(LocationAccess::class)
                && app(LocationAccess::class)->isLocationAdmin($user)));
    }

    public function mount(ChannelSettings $settings): void
    {
        $this->fillData($settings);
    }

    public function save(ChannelSettings $settings): void
    {
        $user = auth()->user();
        $allowed = $settings->scopes($user);

        foreach ($allowed as $scope) {
            foreach (ChannelSettings::TRANSPORTS as $transport) {
                $settings->save($user, $scope['key'], $transport, (array) ($this->data[$scope['key']][$transport] ?? []));
            }
        }

        $this->fillData($settings);
        Notification::make()->title('Kanaleinstellungen gespeichert')->success()->send();
    }

    private function fillData(ChannelSettings $settings): void
    {
        $this->data = [];
        $this->scopeLabels = [];

        foreach ($settings->scopes(auth()->user()) as $scope) {
            $this->scopeLabels[$scope['key']] = $scope['label'];
            foreach (ChannelSettings::TRANSPORTS as $transport) {
                $this->data[$scope['key']][$transport] = [
                    ...$settings->formValues($scope['key'], $transport),
                    'token' => '',
                    'remove_token' => false,
                ];
            }
        }
    }
}
