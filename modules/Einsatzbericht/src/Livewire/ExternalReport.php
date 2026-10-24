<?php

namespace Modules\Einsatzbericht\src\Livewire;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Einsatzbericht\src\Models\ExternalUnit;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\ExternalAccess;
use Modules\Einsatzbericht\src\Services\SituationService;

/**
 * The page behind the QR code, without login: an external Wehr registers once per phone and then
 * sends status and Lagemeldungen. It sees only its own entries.
 */
class ExternalReport extends Component
{
    use WithFileUploads;

    public const COOKIE = 'wehrportal_extern';

    /** The key of the QR address ("key" is taken by Livewire) */
    #[Locked]
    public string $code = '';

    /** Identifies the phone; kept in a cookie */
    #[Locked]
    public string $device = '';

    public string $name = '';

    public string $vehicle = '';

    public string $strength = '';

    public string $text = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $photos = [];

    public ?string $flash = null;

    public function mount(string $code): void
    {
        $this->code = $code;
        $this->device = (string) request()->cookie(self::COOKIE);

        if (! preg_match('/^[A-Za-z0-9]{40}$/', $this->device)) {
            $this->device = Str::random(40);
            Cookie::queue(self::COOKIE, $this->device, 60 * 24 * 3);
        }
    }

    public function register(ExternalAccess $access): void
    {
        $this->run(fn (Report $report) => $access->register($report, $this->device, $this->name, $this->vehicle, $this->strength));
        $this->flash = 'Angemeldet. Jetzt Status und Lagemeldungen senden.';
    }

    public function sendStatus(string $status, ExternalAccess $access): void
    {
        if ($this->run(fn (Report $report) => $access->status($this->unit($report), $status))) {
            $this->flash = 'Status gesendet: '.(ExternalUnit::STATUSES[$status] ?? $status).'.';
        }
    }

    public function sendMessage(ExternalAccess $access): void
    {
        $this->validate([
            'text' => ['nullable', 'string', 'max:2000'],
            'photos' => ['array', 'max:'.SituationService::MAX_PHOTOS],
            'photos.*' => ['image', 'max:'.SituationService::MAX_PHOTO_KB],
        ], ['photos.*.image' => 'Bitte nur Fotos anhängen.', 'photos.*.max' => 'Ein Foto ist größer als 15 MB.', 'photos.max' => 'Höchstens 3 Fotos.']);

        if ($this->run(fn (Report $report) => $access->message($this->unit($report), $this->text, $this->photos))) {
            $this->reset('text', 'photos');
            $this->flash = 'Lagemeldung gesendet.';
        }
    }

    public function render()
    {
        $report = ExternalAccess::find($this->code);
        $unit = $report ? ExternalAccess::unitFor($report, $this->device) : null;

        $statuses = $unit?->statuses()->limit(20)->get() ?? collect();
        $messages = $unit?->messages()->with('photos:id,message_id')->latest('id')->limit(20)->get() ?? collect();

        return view('einsatzbericht::livewire.external-report', [
            'valid' => $report !== null,
            'unit' => $unit,
            'statuses' => $statuses,
            // Status changes and Lagemeldungen in one list, newest first
            'entries' => $statuses->toBase()->merge($messages)->sortByDesc(fn ($e) => $e->created_at->getTimestamp())->values(),
            'alarmDate' => $report?->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m.Y'),
        ]);
    }

    private function unit(Report $report): ExternalUnit
    {
        return ExternalAccess::unitFor($report, $this->device) ?? throw ValidationException::withMessages(['message' => 'Bitte zuerst die Wehr anmelden.']);
    }

    /** Runs an action for a still valid code; false with a message otherwise */
    private function run(callable $action): bool
    {
        $this->resetErrorBag();
        $this->flash = null;
        $report = ExternalAccess::find($this->code);

        if (! $report) {
            $this->addError('message', 'Der Zugang ist gesperrt oder der Einsatz beendet.');

            return false;
        }

        try {
            $action($report);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(in_array($field, ['name', 'strength'], true) ? $field : 'message', $messages[0]);
            }

            return false;
        }

        return true;
    }
}
