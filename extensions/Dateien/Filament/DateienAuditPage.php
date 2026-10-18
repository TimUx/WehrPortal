<?php

namespace Extensions\Dateien\Filament;

use App\Models\User;
use App\Support\Csv;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\Space;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Read-only view of the append-only file audit, with CSV export. Admins only: it names people and files across all areas. */
class DateienAuditPage extends Page
{
    use WithPagination;

    protected static ?string $slug = 'dateien/protokoll';

    protected static ?string $title = 'Dateiprotokoll';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Dateien';

    protected static ?int $navigationSort = 2;

    protected string $view = 'dateien::filament.audit';

    public string $spaceFilter = '';

    public string $actorFilter = '';

    public string $actionFilter = '';

    public string $from = '';

    public string $to = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active && $user->is_admin;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['spaceFilter', 'actorFilter', 'actionFilter', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function export(): StreamedResponse
    {
        abort_unless(self::canAccess(), 403);
        $query = $this->query();
        $names = User::query()->pluck('name', 'id');
        $spaces = Space::query()->pluck('name', 'id');

        return response()->streamDownload(function () use ($query, $names, $spaces) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            Csv::put($out, ['Zeitpunkt', 'Aktion', 'Kamerad', 'Ablage', 'Eintrag', 'Details'], ';');
            foreach ($query->lazyById(1000) as $event) {
                Csv::put($out, [
                    $event->created_at?->timezone('Europe/Berlin')->format('d.m.Y H:i:s'),
                    $event->action,
                    $event->actor_id ? ($names[$event->actor_id] ?? '#'.$event->actor_id) : 'System',
                    $event->space_id ? ($spaces[$event->space_id] ?? '#'.$event->space_id) : '',
                    $event->node_id ? '#'.$event->node_id : '',
                    json_encode($event->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], ';');
            }
            fclose($out);
        }, 'dateiprotokoll-'.now()->timezone('Europe/Berlin')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Builder<AuditEvent> */
    private function query(): Builder
    {
        $query = AuditEvent::query();
        if (ctype_digit($this->spaceFilter)) {
            $query->where('space_id', (int) $this->spaceFilter);
        }
        if (ctype_digit($this->actorFilter)) {
            $query->where('actor_id', (int) $this->actorFilter);
        }
        if ($this->actionFilter !== '') {
            $query->where('action', $this->actionFilter);
        }
        foreach (['from' => '>=', 'to' => '<'] as $field => $operator) {
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $this->{$field})) {
                $day = Carbon::createFromFormat('Y-m-d', $this->{$field}, 'Europe/Berlin')->startOfDay();
                $query->where('created_at', $operator, ($field === 'to' ? $day->addDay() : $day)->utc());
            }
        }

        return $query;
    }

    protected function getViewData(): array
    {
        abort_unless(self::canAccess(), 403);
        /** @var LengthAwarePaginator<int, AuditEvent> $events */
        $events = $this->query()->orderByDesc('created_at')->orderByDesc('id')->paginate(50);
        $actorIds = AuditEvent::query()->whereNotNull('actor_id')->distinct()->pluck('actor_id');

        return [
            'events' => $events,
            'spaces' => Space::query()->orderBy('name')->pluck('name', 'id'),
            'actors' => User::query()->whereIn('id', $actorIds)->orderBy('name')->pluck('name', 'id'),
            'actions' => AuditEvent::query()->distinct()->orderBy('action')->pluck('action'),
            'names' => User::query()->whereIn('id', collect($events->items())->pluck('actor_id')->filter())->pluck('name', 'id'),
        ];
    }
}
