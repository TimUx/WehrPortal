<?php

namespace Modules\Atemschutz\src\Services;

use App\Services\OrganizationSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Atemschutz\src\Models\Operation;
use Modules\Atemschutz\src\Models\Team;

/** The Atemschutzüberwachungsprotokoll of an Einsatz or Übung (FwDV 7) as PDF */
class AtemschutzPdf
{
    public function __construct(private readonly MonitorService $monitor, private readonly OrganizationSettings $org) {}

    public function operation(Operation $operation): string
    {
        $operation->load(['teams.members', 'teams.readings.member', 'teams.warnings.acknowledger', 'starter', 'report']);

        return Pdf::loadView('atemschutz::pdf.operation', [
            'operation' => $operation, 'org' => $this->org->name(),
            'states' => $operation->teams->mapWithKeys(fn (Team $team) => [$team->id => $this->monitor->state($team)]),
        ])->setPaper('a4')->output();
    }
}
