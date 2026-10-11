<?php

namespace Modules\Atemschutz\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Atemschutz\src\Models\Operation;
use Modules\Atemschutz\src\Models\Team;
use Modules\Atemschutz\src\Models\TeamMember;
use Modules\Atemschutz\src\Models\Warning;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\MonitorService;
use Modules\Atemschutz\src\Services\ReadinessService;

/**
 * The Atemschutzüberwachung board: register Trupps by RFID scan or from the pool, start them, note
 * pressures; every Trupp with its clock, expected time, checks, Rückzugsdruck and warnings. Polls
 * every few seconds so several devices show the same; the clocks run in the browser.
 */
class Board extends Component
{
    public Operation $operation;

    public string $teamName = '';

    public string $task = '';

    /** @var list<array{user_id: ?int, name: string, start_pressure: string, is_leader: bool, override: bool, reason: ?string}> */
    public array $draft = [];

    /** The RFID reader types the tag and Enter into this field */
    public string $rfid = '';

    public string $guestName = '';

    /** @var array<int, array<int, string>> pressures by team and member */
    public array $pressures = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->teamName = $this->suggestName();
    }

    public function addByRfid(ReadinessService $readiness): void
    {
        $uid = trim($this->rfid);
        $this->rfid = '';
        $wearer = $uid !== '' ? Wearer::where('rfid_uid', $uid)->with('user')->first() : null;
        if (! $wearer) {
            $this->addError('rfid', 'Unbekannter Chip'.($uid !== '' ? ' ('.$uid.')' : '').'. Der Atemschutzwart ordnet Chips unter „Geräteträger“ zu.');

            return;
        }
        $this->addWearer($wearer, $readiness);
    }

    public function addMember(int $userId, ReadinessService $readiness): void
    {
        $wearer = Wearer::where('user_id', $userId)->with('user')->first();
        if ($wearer) {
            $this->addWearer($wearer, $readiness);
        }
    }

    /** Someone from another Wehr, not in the pool */
    public function addGuest(): void
    {
        $name = trim($this->guestName);
        if ($name !== '' && count($this->draft) < 4) {
            $this->draft[] = ['user_id' => null, 'name' => mb_substr($name, 0, 150), 'start_pressure' => '300', 'is_leader' => $this->draft === [], 'override' => false, 'reason' => 'nicht im Atemschutz-Pool (z. B. andere Wehr)'];
            $this->guestName = '';
        }
    }

    public function setLeader(int $index): void
    {
        foreach ($this->draft as $i => $member) {
            $this->draft[$i]['is_leader'] = $i === $index;
        }
    }

    public function removeMember(int $index): void
    {
        unset($this->draft[$index]);
        $this->draft = array_values($this->draft);
    }

    public function register(MonitorService $monitor): void
    {
        $this->run(function () use ($monitor) {
            $monitor->registerTeam(auth()->user(), $this->operation, $this->teamName, $this->task, $this->draft);
            $this->reset('task', 'draft');
            $this->teamName = $this->suggestName();
        }, 'Trupp registriert.');
    }

    public function startTeam(int $teamId, MonitorService $monitor): void
    {
        $this->run(fn () => $monitor->startTeam(auth()->user(), $this->team($teamId)), 'Uhr läuft.');
    }

    public function removeTeam(int $teamId, MonitorService $monitor): void
    {
        $this->run(fn () => $monitor->removeTeam(auth()->user(), $this->team($teamId)), 'Trupp entfernt.');
    }

    public function record(int $teamId, string $kind, MonitorService $monitor): void
    {
        $kind = in_array($kind, ['check', 'target', 'end'], true) ? $kind : 'check';
        $this->run(function () use ($teamId, $kind, $monitor) {
            $monitor->recordPressures(auth()->user(), $this->team($teamId), $this->pressures[$teamId] ?? [], $kind);
            unset($this->pressures[$teamId]);
        }, match ($kind) {
            'target' => 'Ziel erreicht – Rückzugsdruck berechnet.', 'end' => 'Trupp abgelegt.', default => 'Druck eingetragen.'
        });
    }

    public function withdraw(int $teamId, MonitorService $monitor): void
    {
        $this->run(fn () => $monitor->withdraw(auth()->user(), $this->team($teamId)), 'Rückzug eingetragen.');
    }

    public function acknowledge(int $warningId, MonitorService $monitor): void
    {
        $warning = Warning::whereIn('team_id', $this->operation->teams()->pluck('id'))->findOrFail($warningId);
        $this->run(fn () => $monitor->acknowledge(auth()->user(), $warning), null);
    }

    public function endOperation(MonitorService $monitor)
    {
        $ok = $this->run(fn () => $monitor->endOperation(auth()->user(), $this->operation), 'Überwachung beendet.');

        return $ok ? redirect()->route('atemschutz.board', $this->operation) : null;
    }

    private function addWearer(Wearer $wearer, ReadinessService $readiness): void
    {
        $this->resetErrorBag('rfid');
        if (collect($this->draft)->contains('user_id', $wearer->user_id)) {
            return;
        }
        if (count($this->draft) >= 4) {
            $this->addError('rfid', 'Ein Trupp hat höchstens vier Geräteträger.');

            return;
        }
        $state = $readiness->forWearer($wearer);
        $this->draft[] = [
            'user_id' => $wearer->user_id, 'name' => $wearer->user->name, 'start_pressure' => '300', 'is_leader' => $this->draft === [],
            'override' => false, 'reason' => $state['ready'] ? null : implode(', ', $state['reasons']),
        ];
    }

    private function team(int $id): Team
    {
        return $this->operation->teams()->findOrFail($id);
    }

    private function run(callable $action, ?string $done): bool
    {
        $this->resetErrorBag();
        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
        $this->flash = $done;
        $this->operation->refresh();

        return true;
    }

    private function suggestName(): string
    {
        return 'Trupp '.($this->operation->teams()->count() + 1);
    }

    public function render(MonitorService $monitor, ReadinessService $readiness)
    {
        $teams = $this->operation->teams()->with(['members', 'readings', 'warnings'])->get();
        $states = $teams->mapWithKeys(fn (Team $team) => [$team->id => $monitor->state($team)]);
        if ($this->operation->isRunning()) {
            $teams->where('status', 'active')->each(fn (Team $team) => $monitor->raiseWarnings($team, $states[$team->id]['warnings']));
        }

        $busy = TeamMember::whereNotNull('user_id')->whereHas('team', fn ($q) => $q->where('status', '!=', 'done')->whereHas('operation', fn ($o) => $o->whereNull('ended_at')))->pluck('user_id')->flip();
        $pool = $readiness->forWearers(Wearer::active()->with('user')->get())
            ->reject(fn ($s) => $busy->has($s['wearer']->user_id) || collect($this->draft)->contains('user_id', $s['wearer']->user_id))
            ->sortBy([['ready', 'desc'], [fn ($s) => $s['wearer']->user->name, 'asc']])->values();

        return view('atemschutz::livewire.board', [
            'teams' => $teams,
            'states' => $states,
            'pool' => $pool,
            'warningCount' => $states->sum(fn ($s) => count($s['warnings'])),
            'unacknowledged' => $teams->flatMap->warnings->whereNull('acknowledged_at')->filter(fn ($w) => isset($states[$w->team_id]['warnings'][$w->kind]))->values(),
        ]);
    }
}
