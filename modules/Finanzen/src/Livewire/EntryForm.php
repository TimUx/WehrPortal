<?php

namespace Modules\Finanzen\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Finanzen\src\Models\CostCenter;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Services\FinanceService;
use Modules\Finanzen\src\Support\Money;

/**
 * Submit an Ausgabe with Beleg (every Kamerad), book an Ausgabe or Einnahme directly (Kassenwart,
 * $direct), or change one.
 */
class EntryForm extends Component
{
    use WithFileUploads;

    public ?Entry $entry = null;

    public bool $direct = false;

    public string $kind = 'expense';

    public string $costCenterId = '';

    public string $title = '';

    public string $description = '';

    public string $vendor = '';

    public string $amount = '';

    public string $bookedOn = '';

    public string $paymentNote = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(?Entry $entry = null, bool $direct = false): void
    {
        $this->entry = $entry?->exists ? $entry : null;
        $this->direct = $direct && FinanceService::canManage(auth()->user());
        $this->bookedOn = FinanceService::today()->toDateString();

        if ($this->entry) {
            abort_unless(FinanceService::canEdit(auth()->user(), $this->entry), 403);
            $this->fill([
                'kind' => $this->entry->kind, 'costCenterId' => (string) $this->entry->cost_center_id, 'title' => $this->entry->title,
                'description' => (string) $this->entry->description, 'vendor' => (string) $this->entry->vendor,
                'amount' => Money::input($this->entry->amount_cents), 'bookedOn' => $this->entry->booked_on->toDateString(),
                'paymentNote' => (string) $this->entry->payment_note,
            ]);
        }
    }

    public function removeFile(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function save(FinanceService $finance)
    {
        $this->validate(['uploads.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf']], [
            'uploads.*.max' => 'Ein Beleg darf höchstens 10 MB groß sein.', 'uploads.*.mimes' => 'Bitte ein Foto (JPG, PNG) oder eine PDF-Datei hochladen.',
        ]);

        $data = [
            'kind' => $this->kind, 'cost_center_id' => (int) $this->costCenterId, 'title' => $this->title, 'description' => $this->description,
            'vendor' => $this->vendor, 'amount_cents' => Money::parse($this->amount), 'booked_on' => $this->bookedOn,
            ...($this->direct || ($this->entry && FinanceService::canManage(auth()->user())) ? ['payment_note' => $this->paymentNote] : []),
        ];

        if (! $this->entry && ! $this->direct && $this->uploads === []) {
            $this->addError('uploads', 'Bitte ein Foto oder eine PDF des Belegs anhängen.');

            return null;
        }

        try {
            $entry = $this->entry
                ? $finance->update(auth()->user(), $this->entry, $data, $this->uploads)
                : $finance->create(auth()->user(), $data, $this->uploads, $this->direct);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'files' ? 'uploads' : $field, $messages[0]);
            }

            return null;
        }

        session()->flash('finanzen.flash', match (true) {
            $this->entry !== null => 'Gespeichert.',
            $this->direct => ($entry->isIncome() ? 'Einnahme' : 'Ausgabe').' '.$entry->code().' gebucht.',
            default => 'Eingereicht. Kassenwart und Wehrführung sind informiert.',
        });

        return redirect()->route('finanzen.entry', $entry);
    }

    public function render()
    {
        return view('finanzen::livewire.entry-form', [
            'centers' => CostCenter::ordered()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $this->entry?->cost_center_id))->get(),
            'canManage' => FinanceService::canManage(auth()->user()),
            'existing' => $this->entry?->receipts()->get(['id', 'entry_id', 'name', 'mime', 'size']) ?? collect(),
        ]);
    }
}
