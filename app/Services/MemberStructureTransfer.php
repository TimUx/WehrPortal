<?php

namespace App\Services;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use App\Support\Csv;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abteilungen and Funktionen as CSV for Excel (UTF-8, semicolon), e.g. to move them from one
 * instance to another. Members are listed by e-mail address. The import adds and updates, it never
 * deletes; members are only added, unknown addresses are reported.
 */
class MemberStructureTransfer
{
    public const GROUP_HEADER = ['Abteilung', 'Reihenfolge', 'Mitglieder'];

    public const FUNCTION_HEADER = ['Funktion', 'Unterfunktion', 'Reihenfolge', 'Mitglieder'];

    public function exportGroups(): string
    {
        $withLocations = Group::locationsAvailable();
        /** @var \Illuminate\Database\Eloquent\Collection<int, Group> $groups */
        $groups = Group::query()
            ->with(['users:id,email', ...($withLocations ? ['location'] : [])])
            ->orderBy('sort_order')->orderBy('name')->get();
        $rows = $groups->map(fn (Group $g) => $withLocations
            ? [$g->name, $g->location?->getAttribute('name'), $g->sort_order, $this->emails($g->users)]
            : [$g->name, $g->sort_order, $this->emails($g->users)]);

        return $this->csv($withLocations ? ['Abteilung', 'Standort', 'Reihenfolge', 'Mitglieder'] : self::GROUP_HEADER, $rows);
    }

    /** One row per function; a function with sub-functions gets its own row and one per sub-function */
    public function exportFunctions(): string
    {
        $rows = collect();

        foreach (MemberFunction::topLevel()->with(['users:id,email', 'children.users:id,email'])->orderBy('sort_order')->orderBy('name')->get() as $function) {
            $rows->push([$function->name, '', $function->sort_order, $this->emails($function->users)]);

            foreach ($function->children->sortBy([['sort_order', 'asc'], ['name', 'asc']]) as $child) {
                $rows->push([$function->name, $child->name, $child->sort_order, $this->emails($child->users)]);
            }
        }

        return $this->csv(self::FUNCTION_HEADER, $rows);
    }

    /** @return array{created: int, updated: int, members: int, unknown: list<string>} */
    public function importGroups(string $content, bool $withMembers): array
    {
        $rows = $this->parse($content, ['abteilung']);
        $stats = ['created' => 0, 'updated' => 0, 'members' => 0, 'unknown' => []];

        DB::transaction(function () use ($rows, $withMembers, &$stats) {
            foreach ($rows as $row) {
                $name = trim($row['abteilung'] ?? '');
                if ($name === '') {
                    continue;
                }

                $group = $this->groupForImport($name, trim($row['standort'] ?? ''));
                $group ??= new Group(['name' => $name]);
                $stats[$group->exists ? 'updated' : 'created']++;
                $group->sort_order = is_numeric($row['reihenfolge'] ?? null) ? (int) $row['reihenfolge'] : ($group->sort_order ?? (int) Group::max('sort_order') + 1);
                $group->save();

                if ($withMembers) {
                    $this->addMembers($group->users(), $row['mitglieder'] ?? '', $stats);
                }
            }
        });

        $stats['unknown'] = array_values(array_unique($stats['unknown']));

        return $stats;
    }

    private function groupForImport(string $name, string $locationName): ?Group
    {
        if (! Group::locationsAvailable()) {
            return Group::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        }

        if ($locationName !== '') {
            $locations = Location::query()->whereRaw('lower(name) = ?', [mb_strtolower($locationName)])->get();
            if ($locations->count() !== 1) {
                throw ValidationException::withMessages([
                    'file' => $locations->isEmpty()
                        ? "Der Standort „{$locationName}“ gibt es nicht."
                        : "Der Standort „{$locationName}“ ist nicht eindeutig.",
                ]);
            }

            $location = $locations->first();
            $user = auth()->user();
            if ($user && ! app(LocationAccess::class)->canManage($user, $location)) {
                throw ValidationException::withMessages(['file' => "Du darfst den Standort „{$locationName}“ nicht verwalten."]);
            }

            return Group::where('ext_location_id', $location->getKey())
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        }

        $matches = Group::whereRaw('lower(name) = ?', [mb_strtolower($name)])->limit(2)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'file' => "Die Abteilung „{$name}“ gibt es an mehreren Standorten. Bitte die Spalte „Standort“ ergänzen.",
            ]);
        }

        return $matches->first();
    }

    /** @return array{created: int, updated: int, members: int, unknown: list<string>} */
    public function importFunctions(string $content, bool $withMembers): array
    {
        $rows = $this->parse($content, ['funktion']);
        $stats = ['created' => 0, 'updated' => 0, 'members' => 0, 'unknown' => []];

        DB::transaction(function () use ($rows, $withMembers, &$stats) {
            foreach ($rows as $row) {
                $name = trim($row['funktion'] ?? '');
                $childName = trim($row['unterfunktion'] ?? '');
                if ($name === '') {
                    continue;
                }

                $order = is_numeric($row['reihenfolge'] ?? null) ? (int) $row['reihenfolge'] : null;
                $parent = MemberFunction::topLevel()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

                if (! $parent) {
                    $parent = MemberFunction::create(['name' => $name, 'sort_order' => $childName === '' && $order !== null ? $order : (int) MemberFunction::topLevel()->max('sort_order') + 1]);
                    $stats['created']++;
                } elseif ($childName === '') {
                    $stats['updated']++;
                    if ($order !== null) {
                        $parent->update(['sort_order' => $order]);
                    }
                }

                $target = $parent;

                if ($childName !== '') {
                    $target = $parent->children()->whereRaw('lower(name) = ?', [mb_strtolower($childName)])->first();
                    if ($target) {
                        $stats['updated']++;
                        if ($order !== null) {
                            $target->update(['sort_order' => $order]);
                        }
                    } else {
                        $target = $parent->children()->create(['name' => $childName, 'sort_order' => $order ?? (int) $parent->children()->max('sort_order') + 1]);
                        $stats['created']++;
                    }
                }

                if ($withMembers) {
                    $this->addMembers($target->users(), $row['mitglieder'] ?? '', $stats);
                }
            }
        });

        $stats['unknown'] = array_values(array_unique($stats['unknown']));

        return $stats;
    }

    private function addMembers($relation, string $emails, array &$stats): void
    {
        $addresses = collect(preg_split('/[,\s]+/', mb_strtolower($emails)))->filter()->unique();
        if ($addresses->isEmpty()) {
            return;
        }

        $users = User::whereIn(DB::raw('lower(email)'), $addresses->all())->get(['id', 'email']);
        $stats['unknown'] = [...$stats['unknown'], ...$addresses->diff($users->pluck('email')->map(fn ($e) => mb_strtolower($e)))->all()];
        $stats['members'] += count($relation->syncWithoutDetaching($users->pluck('id'))['attached']);
    }

    private function emails(Collection $users): string
    {
        return $users->pluck('email')->sort()->implode(', ');
    }

    private function csv(array $header, Collection $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        Csv::put($out, $header, ';');
        foreach ($rows as $row) {
            Csv::put($out, $row, ';');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /**
     * Rows keyed by the lower-case column name; semicolon or comma, with or without BOM.
     *
     * @param  list<string>  $required
     * @return list<array<string, string>>
     */
    private function parse(string $content, array $required): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $delimiter = substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), str_getcsv(array_shift($lines) ?? '', $delimiter, '"', '\\'));

        if (array_diff($required, $header)) {
            throw ValidationException::withMessages(['file' => 'Die Datei hat nicht die erwarteten Spalten ('.implode(';', $required).' …). Am besten erst exportieren und diese Datei als Vorlage nehmen.']);
        }

        return collect($lines)->filter(fn ($l) => trim($l) !== '')
            ->map(fn ($l) => array_combine($header, array_pad(array_slice(str_getcsv($l, $delimiter, '"', '\\'), 0, count($header)), count($header), '')))
            ->values()->all();
    }
}
