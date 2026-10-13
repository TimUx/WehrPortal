<?php

namespace App\Services;

/**
 * Sections of the Jahresbericht (e.g. for the Jahreshauptversammlung). Each module adds what it
 * knows about a year – Einsätze, Dienste, Mitglieder, Lehrgänge, Fahrten … – as rendered HTML;
 * sections of switched-off modules are left out.
 */
class AnnualReportRegistry
{
    /** @var array<string, array{module: string, title: string, order: int, render: callable(int): ?string}> */
    private array $sections = [];

    /** @param  callable(int $year): ?string  $render  HTML of the section, or null when there is nothing */
    public function register(string $module, string $title, callable $render, int $order = 50): void
    {
        $this->sections[$module.'.'.$title] = compact('module', 'title', 'order', 'render');
    }

    /** @return list<array{title: string, html: string}> */
    public function for(int $year): array
    {
        $registry = app(ModuleRegistry::class);

        return collect($this->sections)
            ->filter(fn ($s) => $registry->isActive($s['module']))
            ->sortBy('order')
            ->map(fn ($s) => ['title' => $s['title'], 'html' => ($s['render'])($year)])
            ->filter(fn ($s) => filled($s['html']))
            ->values()->all();
    }
}
