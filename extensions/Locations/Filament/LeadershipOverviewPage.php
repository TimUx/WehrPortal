<?php

namespace Extensions\Locations\Filament;

use App\Filament\ModuleArea;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LeadershipAssignments;
use Extensions\Locations\Services\LeadershipProjections;
use Extensions\Locations\Services\StandardRoleAssignments;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Url;

class LeadershipOverviewPage extends Page implements ModuleArea
{
    protected static ?string $slug = 'fuehrungsuebersicht';

    protected static ?string $navigationLabel = 'Einsatzzahlen';

    protected static ?string $title = 'Führungsübersicht';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Führungsübersicht';

    protected static ?int $navigationSort = 1;

    protected string $view = 'locations::filament.leadership-overview';

    #[Url(as: 'jahr')]
    public string $year = '';

    public static function allows(User $user): bool
    {
        return $user->is_active
            && app(LeadershipAssignments::class)->projectionEnabled('einsatzbericht')
            && app(StandardRoleAssignments::class)->hasGlobalProfile($user, 'buergermeister');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && static::allows($user);
    }

    public function mount(): void
    {
        $this->year = (string) now('Europe/Berlin')->year;
    }

    public function updatedYear(): void
    {
        $this->validateOnly('year', [
            'year' => ['required', 'integer', 'min:1900', 'max:'.now('Europe/Berlin')->year],
        ]);
    }

    protected function getViewData(): array
    {
        $actor = User::withoutGlobalScopes()->findOrFail(auth()->id());
        abort_unless(static::allows($actor), 403);

        $year = (int) Validator::make(['year' => $this->year], [
            'year' => ['required', 'integer', 'min:1900', 'max:'.now('Europe/Berlin')->year],
        ])->validate()['year'];
        $projection = app(LeadershipProjections::class);
        $summaries = Location::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Location $location): array => [
                'location' => $location->name,
                ...$projection->read($actor, (int) $location->getKey(), 'incident_totals', $year),
            ])->all();

        return [
            'year' => $year,
            'years' => range(now('Europe/Berlin')->year, max(1900, now('Europe/Berlin')->year - 5)),
            'summaries' => $summaries,
        ];
    }
}
