<?php

namespace Modules\Wartung\src\Services;

use App\Services\OrganizationSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Wartung\src\Models\Attachment;
use Modules\Wartung\src\Models\Protocol;
use Modules\Wartung\src\Models\Template;

/**
 * PDFs of the Wartung: the signed Prüfprotokoll with the signature and photos embedded (for DGUV
 * evidence and insurers) and a template as an empty checklist to fill in by hand.
 */
class WartungPdf
{
    public function __construct(private readonly WartungService $service, private readonly OrganizationSettings $org) {}

    public function protocol(Protocol $protocol): string
    {
        $photos = $protocol->attachments()->get()->filter(fn (Attachment $a) => $a->isImage())
            ->map(fn (Attachment $a) => ['name' => $a->name, 'src' => $this->jpeg((string) base64_decode($a->data))])
            ->filter(fn ($photo) => $photo['src'] !== null)->values();

        return Pdf::loadView('wartung::pdf.protocol', [
            'protocol' => $protocol, 'photos' => $photos, 'org' => $this->org->name(),
            'files' => $protocol->attachments()->get(['id', 'protocol_id', 'name', 'mime', 'size'])->reject(fn (Attachment $a) => $a->isImage()),
            'intact' => $this->service->verify($protocol),
        ])->setPaper('a4')->output();
    }

    public function template(Template $template): string
    {
        return Pdf::loadView('wartung::pdf.template', ['template' => $template, 'org' => $this->org->name()])->setPaper('a4')->output();
    }

    /** dompdf can't be relied on for WebP; the photos go in as JPEG */
    private function jpeg(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return null;
        }
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
