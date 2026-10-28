<?php

namespace Modules\Finanzen\src\Livewire;

use Extensions\Locations\Models\Location;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Services\FinanceService;

/**
 * The own Ausgaben with their status for every Kamerad. Kassenwart, Wehrführung and admins (or everyone,
 * when the Kassenwart makes it public) also see the status of every Kostenstelle – with the budget when
 * it is switched on – and the approved and paid Ausgaben of the year (without Belege).
 */
class Overview extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    #[Url(as: 'standort')]
    public string $locationId = '';

    public function mount(): void
    {
        $this->year ??= FinanceService::today()->year;
        $user = auth()->user();
        if (FinanceService::locationsEnabled() && FinanceService::canReview($user)
            && ! FinanceService::canReviewGlobally($user) && ! FinanceService::settings()['public']) {
            $this->locationId = (string) (FinanceService::overviewLocationOptions($user)->first()->id ?? '');
        }
    }

    public function render(FinanceService $finance)
    {
        $user = auth()->user();
        $locations = FinanceService::overviewLocationOptions($user);
        $locationId = null;
        if (FinanceService::locationsEnabled()) {
            if ($this->locationId !== '') {
                $locationId = ctype_digit($this->locationId) ? (int) $this->locationId : null;
                abort_unless($locationId !== null && $locations->contains('id', $locationId), 403);
            } elseif (FinanceService::canReview($user) && ! FinanceService::canReviewGlobally($user)
                && ! FinanceService::settings()['public']) {
                abort(403);
            }
        }
        $full = FinanceService::canSeeOverview($user);
        $overview = $full ? $finance->overview($this->year, activeOnly: true, locationId: $locationId)
            ->filter(fn ($row) => $row['budget'] > 0 || $row['approved'] + $row['paid'] + $row['income'] > 0 || $row['center']->is_active) : collect();
        $entryRelations = ['costCenter'];
        if (FinanceService::locationsEnabled()) {
            $entryRelations[] = 'location';
        }

        return view('finanzen::livewire.overview', [
            'overview' => $overview,
            'totals' => $finance->totals($overview),
            'years' => $finance->years(),
            'closed' => FinanceService::isClosed($this->year),
            'mine' => Entry::with($entryRelations)->where('submitted_by', $user->id)->where('kind', 'expense')
                ->where(fn ($q) => $q->where('year', $this->year)->orWhereIn('status', ['submitted', 'approved']))
                ->latest('id')->get(),
            'full' => $full,
            'budgetOn' => FinanceService::budgetEnabled(),
            'spent' => ! $full ? collect() : Entry::with($entryRelations)->where('year', $this->year)->where('kind', 'expense')->whereIn('status', Entry::BOOKED)
                ->when(FinanceService::locationsEnabled() && $locationId !== null, fn ($query) => $query->where('location_id', $locationId))
                ->when(FinanceService::locationsEnabled() && $locationId === null && FinanceService::canReview($user) && ! FinanceService::canReviewGlobally($user), fn ($query) => $query->whereRaw('1 = 0'))
                ->orderByDesc('booked_on')->orderByDesc('number')->get(),
            'toReview' => FinanceService::canReview($user) ? FinanceService::scopeReviewEntries($user, Entry::query())->where('status', 'submitted')->count() : 0,
            'canReview' => FinanceService::canReview($user),
            'canManage' => FinanceService::canManage($user),
            'locationsEnabled' => FinanceService::locationsEnabled(),
            'locations' => $locations,
            'selectedLocation' => $locationId ? Location::find($locationId) : null,
        ]);
    }
}
