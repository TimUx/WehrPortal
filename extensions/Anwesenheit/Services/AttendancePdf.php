<?php

namespace Extensions\Anwesenheit\Services;

use App\Services\OrganizationSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Extensions\Anwesenheit\Models\AttendanceList;

/** The Anwesenheitsliste as PDF */
class AttendancePdf
{
    public function __construct(private readonly OrganizationSettings $org) {}

    public function render(AttendanceList $list): string
    {
        $list->loadMissing(['leader', 'participants', 'creator', 'group']);

        return Pdf::loadView('anwesenheit::pdf.list', ['list' => $list, 'org' => $this->org->name()])->setPaper('a4')->output();
    }

    public static function filename(AttendanceList $list): string
    {
        return 'Anwesenheitsliste-'.($list->starts_at?->timezone('Europe/Berlin')->format('Y-m-d') ?? 'ohne-datum').'-'.$list->id.'.pdf';
    }
}
