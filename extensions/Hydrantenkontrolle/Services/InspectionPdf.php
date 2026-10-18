<?php

namespace Extensions\Hydrantenkontrolle\Services;

use App\Services\OrganizationSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Extensions\Hydrantenkontrolle\Models\InspectionYear;
use Extensions\Hydrantenkontrolle\Support\Checklist;
use Illuminate\Support\Facades\Gate;

class InspectionPdf
{
    public function render(InspectionYear $year): string
    {
        Gate::authorize('manage-hydrantenkontrolle');
        $year = InspectionYear::findOrFail($year->id);
        $inspections = $year->inspections()->orderBy('snapshot->number')->get();

        return Pdf::loadView('hydrantenkontrolle::pdf.year', [
            'year' => $year,
            'inspections' => $inspections,
            'org' => app(OrganizationSettings::class)->name(),
            'defects' => Checklist::DEFECTS,
            'createdAt' => now()->timezone('Europe/Berlin'),
        ])->setPaper('a4')->setOption('isRemoteEnabled', false)->output();
    }
}
