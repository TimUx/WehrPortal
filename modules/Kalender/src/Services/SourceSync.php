<?php

namespace Modules\Kalender\src\Services;

use App\Support\SafeUrl;
use Illuminate\Support\Facades\DB;
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
            // Only public addresses: the server must not be steered to internal hosts (SSRF)
            $response = SafeUrl::get(preg_replace('#^webcal://#i', 'https://', $source->url), ['Accept' => 'text/calendar']);

            if (! $response->successful()) {
                throw new \RuntimeException('Der Kalender ist unter dieser Adresse nicht abrufbar.');
            }

            if (! str_contains($response->body(), 'BEGIN:VCALENDAR')) {
                throw new \RuntimeException('Unter dieser Adresse liegt kein iCal-Kalender.');
            }

            $events = $this->parser->parse($response->body(), now()->subDays(30), now()->addYear());
        } catch (Throwable $e) {
            // Our own messages only; network details (refused, timeout …) would help probing the network
            $source->update(['last_error' => $e instanceof \RuntimeException ? mb_substr($e->getMessage(), 0, 500) : 'Der Kalender ist unter dieser Adresse nicht abrufbar.']);

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
