<?php

namespace Modules\Lehrgaenge\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Services\CatalogImporter;
use RuntimeException;

/**
 * The Standardliste for the Ausbildungsleiter: all known Lehrgänge, whether fox112 lists them now,
 * and whether they stay offered without fox112. Lehrgänge missing in fox112 can be added by hand.
 */
class CourseList extends Component
{
    public string $search = '';

    public string $newName = '';

    public ?string $flash = null;

    /** The Restplätze page of the own Kreis on fox112 */
    public string $foxUrl = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->canManageLehrgaenge(), 403);
    }

    public function mount(): void
    {
        $this->foxUrl = (string) CatalogImporter::url();
    }

    public function saveFoxUrl(CatalogImporter $importer): void
    {
        $this->resetErrorBag();

        try {
            $url = CatalogImporter::saveUrl($this->foxUrl);
        } catch (ValidationException $e) {
            $this->addError('foxUrl', collect($e->errors())->flatten()->first());

            return;
        }

        $this->foxUrl = (string) $url;
        if ($url === null) {
            $this->flash = 'fox112-Adresse entfernt. Es werden keine Lehrgänge mehr eingelesen.';

            return;
        }

        try {
            $importer->import();
            $this->flash = 'fox112-Adresse gespeichert. '.CatalogImporter::lastRun()['message'];
        } catch (RuntimeException $e) {
            $this->addError('foxUrl', 'Gespeichert, aber das Einlesen klappt nicht: '.$e->getMessage());
        }
    }

    public function add(): void
    {
        $this->validate(['newName' => ['required', 'string', 'max:150']], ['newName.required' => 'Bitte den Namen des Lehrgangs angeben.']);
        $name = trim(preg_replace('/\s+/u', ' ', $this->newName));
        $course = Course::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($course?->is_standard) {
            $this->addError('newName', '„'.$course->name.'“ steht schon auf der Standardliste.');

            return;
        }

        $course ? $course->update(['is_standard' => true]) : Course::create(['name' => $name, 'is_standard' => true]);
        $this->flash = '„'.$name.'“ steht auf der Standardliste.';
        $this->newName = '';
    }

    public function toggle(int $courseId): void
    {
        $course = Course::findOrFail($courseId);
        $course->update(['is_standard' => ! $course->is_standard]);
        $this->flash = $course->is_standard
            ? '„'.$course->name.'“ steht auf der Standardliste.'
            : '„'.$course->name.'“ ist von der Standardliste genommen'.($course->listed ? ' und bleibt angeboten, solange fox112 ihn führt.' : ' und wird nicht mehr angeboten.');
    }

    public function render()
    {
        $term = trim($this->search);

        return view('lehrgaenge::livewire.course-list', [
            'courses' => Course::withCount(['records as open_count' => fn ($q) => $q->where('status', 'requested')])
                ->when($term !== '', fn ($q) => $q->where('name', 'ilike', '%'.$term.'%'))
                ->orderBy('name')->get(),
        ]);
    }
}
