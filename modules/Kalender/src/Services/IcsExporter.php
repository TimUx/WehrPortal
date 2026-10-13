<?php

namespace Modules\Kalender\src\Services;

use App\Models\DressCode;
use App\Services\OrganizationSettings;
use Illuminate\Support\Collection;
use Modules\Kalender\src\Models\Event;

/** Writes events as an iCalendar feed for phone and desktop calendars */
class IcsExporter
{
    /** $withLinks adds the link into the app, which only makes sense for Kameraden */
    public function render(Collection $events, bool $withLinks = true): string
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'openwehr';
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//openwehr//Kalender//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape(app(OrganizationSettings::class)->shortName()),
            'X-WR-TIMEZONE:Europe/Berlin',
        ];

        foreach ($events as $event) {
            /** @var Event $event */
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:event-'.$event->id.'@'.$host;
            $lines[] = 'DTSTAMP:'.$event->updated_at->copy()->utc()->format('Ymd\THis\Z');

            if ($event->all_day) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$event->starts_at->copy()->setTimezone('Europe/Berlin')->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$event->ends_at->copy()->setTimezone('Europe/Berlin')->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$event->starts_at->copy()->utc()->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$event->ends_at->copy()->utc()->format('Ymd\THis\Z');
            }

            $lines[] = 'SUMMARY:'.$this->escape(($event->isCancelled() ? 'ABGESAGT: ' : '').$event->title);
            $lines[] = 'CATEGORIES:'.$this->escape($event->typeLabel());
            if ($event->place || $event->location) {
                $lines[] = 'LOCATION:'.$this->escape($event->place?->label() ?? $event->location);
            }
            $dress = DressCode::names($event->dress_code_ids);
            if ($event->description || $dress || $event->partners) {
                $lines[] = 'DESCRIPTION:'.$this->escape(trim(($event->partners ? 'Gemeinsam mit '.$event->partners."\n" : '').($dress ? 'Kleidung: '.implode(', ', $dress)."\n" : '').(string) $event->description));
            }
            if ($event->isCancelled()) {
                $lines[] = 'STATUS:CANCELLED';
            }
            if ($withLinks) {
                $lines[] = 'URL:'.route('kalender.show', $event);
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn ($line) => $this->fold($line), $lines))."\r\n";
    }

    private function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', ';' => '\;', ',' => '\\,', "\r\n" => '\\n', "\n" => '\\n']);
    }

    /** Lines longer than 75 bytes continue on the next line after a space, without splitting characters */
    private function fold(string $line): string
    {
        $out = '';
        $chunk = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($chunk.$char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ").$chunk;
                $chunk = '';
            }
            $chunk .= $char;
        }

        return $out === '' ? $chunk : $out."\r\n ".$chunk;
    }
}
