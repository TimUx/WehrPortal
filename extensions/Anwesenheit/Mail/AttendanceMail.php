<?php

namespace Extensions\Anwesenheit\Mail;

use App\Services\OrganizationSettings;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Services\AttendancePdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The finished list with the PDF and every uploaded file attached */
class AttendanceMail extends Mailable
{
    public function __construct(public AttendanceList $list, private readonly string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Anwesenheitsliste: '.$this->list->title.' ('.$this->list->starts_at?->timezone('Europe/Berlin')->format('d.m.Y').')');
    }

    public function content(): Content
    {
        return new Content(view: 'anwesenheit::mail.list', with: ['list' => $this->list, 'org' => app(OrganizationSettings::class)]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $attachments = [Attachment::fromData(fn () => $this->pdf, AttendancePdf::filename($this->list))->withMime('application/pdf')];

        foreach ($this->list->files as $file) {
            $attachments[] = Attachment::fromData(fn () => $file->bytes(), $file->filename);
        }

        return $attachments;
    }
}
