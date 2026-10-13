<?php

namespace Modules\Lehrgaenge\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Services\CatalogImporter;
use Modules\Lehrgaenge\src\Services\RegistrationService;
use RuntimeException;

/**
 * The Lehrgangsliste for the Ausbildungsleiter: what is offered, the suggestions from fox112, the offered
 * Lehrgänge that left fox112, deleting, and Lehrgänge missing in fox112 added by hand.
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

        if ($course?->isOffered()) {
            $this->addError('newName', '„'.$course->name.'“ steht schon auf der Lehrgangsliste.');

            return;
        }

        $course ? $this->offer($course) : Course::create(['name' => $name, 'is_standard' => true]);
        $this->flash = '„'.$name.'“ steht auf der Lehrgangsliste und wird angeboten.';
        $this->newName = '';
    }

    /** A suggestion from fox112 (or a Lehrgang taken off the list) is offered to the Kameraden */
    public function accept(int $courseId): void
    {
        $course = Course::findOrFail($courseId);
        $this->offer($course);
        $this->flash = '„'.$course->name.'“ wird angeboten.';
    }

    public function acceptAll(): void
    {
        $courses = Course::whereNotNull('suggested_at')->get();
        $courses->each(fn (Course $c) => $this->offer($c));
        $this->flash = $courses->count().' Lehrgänge werden angeboten.';
    }

    /** Not offered, and fox112 doesn't suggest it again – until "doch vorschlagen" */
    public function ignore(int $courseId): void
    {
        $course = Course::findOrFail($courseId);
        $course->update(['suggested_at' => null, 'ignored_at' => now(), 'is_standard' => false]);
        $this->flash = '„'.$course->name.'“ wird nicht angeboten und nicht mehr vorgeschlagen.';
    }

    public function unignore(int $courseId): void
    {
        $course = Course::findOrFail($courseId);
        $course->update(['ignored_at' => null, 'suggested_at' => $course->listed ? now() : null]);
        $this->flash = '„'.$course->name.'“ wird wieder vorgeschlagen, sobald fox112 ihn führt.';
    }

    /** Left fox112, but stays: maybe it is only dropped for a while */
    public function keep(int $courseId): void
    {
        $course = Course::findOrFail($courseId);
        $course->update(['gone_since' => null]);
        $this->flash = '„'.$course->name.'“ bleibt angeboten, auch ohne fox112.';
    }

    /**
     * Takes a Lehrgang off the list. Without anything on record it is deleted; when Kameraden have it absolviert
     * or asked for it, it is only hidden, so their entries keep their Lehrgang. fox112 may suggest it again.
     */
    public function remove(int $courseId): void
    {
        $course = Course::withCount('records')->findOrFail($courseId);
        $course->records_count
            ? $course->update(['removed_at' => now(), 'is_standard' => false, 'suggested_at' => null, 'gone_since' => null])
            : $course->delete();
        $this->flash = '„'.$course->name.'“ ist von der Lehrgangsliste gelöscht'.($course->records_count ? ' – die Einträge der Kameraden bleiben.' : '.');
    }

    private function offer(Course $course): void
    {
        $course->update(['is_standard' => true, 'suggested_at' => null, 'ignored_at' => null, 'removed_at' => null]);
    }

    /** Kürzel, shown next to the Kameraden who have the Lehrgang */
    public function setShort(int $courseId, string $short): void
    {
        $course = Course::findOrFail($courseId);
        $course->update(['short' => trim($short) !== '' ? mb_substr(trim($short), 0, 12) : null]);
        $this->flash = '„'.$course->name.'“: '.($course->short ? 'Kürzel „'.$course->short.'“.' : 'ohne Kürzel.');
    }

    /** Wiederholungsintervall in months; empty = none */
    public function setRepeat(int $courseId, string $months, RegistrationService $registrations): void
    {
        $this->resetErrorBag();
        $course = Course::findOrFail($courseId);
        try {
            $registrations->setRepeat($course, trim($months) === '' ? null : (int) $months);
        } catch (ValidationException $e) {
            $this->addError('repeat', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = '„'.$course->name.'“: '.($course->repeat_months ? 'Auffrischung alle '.$course->repeat_months.' Monate.' : 'ohne Auffrischung.');
    }

    public function toggle(int $courseId): void
    {
        $course = Course::findOrFail($courseId);
        $course->update(['is_standard' => ! $course->is_standard, 'suggested_at' => null]);
        $this->flash = '„'.$course->name.'“ '.($course->is_standard ? 'wird angeboten.' : 'wird nicht mehr angeboten – Kameraden können kein Interesse mehr melden.');
    }

    public function render()
    {
        $term = trim($this->search);

        return view('lehrgaenge::livewire.course-list', [
            'courses' => Course::onList()->whereNull('suggested_at')->withCount(['records as open_count' => fn ($q) => $q->where('status', 'requested')])
                ->when($term !== '', fn ($q) => $q->where('name', 'ilike', '%'.$term.'%'))
                ->orderBy('name')->get(),
            'suggestions' => Course::whereNotNull('suggested_at')->orderBy('name')->get(),
            'gone' => Course::onList()->whereNotNull('gone_since')->withCount(['records as open_count' => fn ($q) => $q->where('status', 'requested')])->orderBy('name')->get(),
            'ignored' => Course::whereNotNull('ignored_at')->orderBy('name')->get(),
        ]);
    }
}
