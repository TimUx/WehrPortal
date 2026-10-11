<?php

namespace Modules\Kalender\src\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Source;
use Throwable;

/** Pulls external iCal calendars in; their events are read-only and replaced on every sync */
class SourceSync
{
    public function __construct(private readonly IcsParser $parser) {}

    /** @return int number of events now held from this source, or -1 on failure */
    public function sync(Source $source): int
    {
        try {
            $response = Http::timeout(20)->withHeaders(['Accept' => 'text/calendar'])
                ->get(preg_replace('#^webcal://#i', 'https://', $source->url));

            if (! $response->successful()) {
                throw new \RuntimeException('Der Kalender antwortet mit Fehler '.$response->status().'.');
            }

            if (! str_contains($response->body(), 'BEGIN:VCALENDAR')) {
                throw new \RuntimeException('Unter dieser Adresse liegt kein iCal-Kalender.');
            }

            $events = $this->parser->parse($response->body(), now()->subDays(30), now()->addYear());
        } catch (Throwable $e) {
            $source->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return -1;
        }

        DB::transaction(function () use ($source, $events) {
            $uids = [];

            foreach ($events as $data) {
                $uids[] = $data['uid'];
                Event::updateOrCreate(
                    ['source_id' => $source->id, 'external_uid' => $data['uid']],
                    [
                        'title' => mb_substr($data['title'], 0, 255),
                        'type' => $source->type,
                        'starts_at' => $data['starts_at'],
                        'ends_at' => $data['ends_at'],
                        'all_day' => $data['all_day'],
                        'location' => $data['location'] ? mb_substr($data['location'], 0, 255) : null,
                        'description' => $data['description'],
                        'rsvp' => false,
                    ],
                );
            }

            // What is gone from the feed is gone here as well
            $source->events()->whereNotIn('external_uid', $uids ?: ['__none__'])->delete();
            $source->update(['last_synced_at' => now(), 'last_error' => null]);
        });

        return count($events);
    }

    public function syncAll(): array
    {
        return Source::where('is_active', true)->get()->mapWithKeys(fn (Source $s) => [$s->name => $this->sync($s)])->all();
    }
}
