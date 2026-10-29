<?php

/** Pages photographed by tests/e2e: "<module>/<name>" from tests/e2e/fixtures/pages.ts */
function screenshotPages(): array
{
    preg_match_all("/module: '([^']+)', name: '([^']+)'/", file_get_contents(base_path('tests/e2e/fixtures/pages.ts')), $matches, PREG_SET_ORDER);

    return array_map(fn (array $match) => "{$match[1]}/{$match[2]}", $matches);
}

/** Screenshot files a Markdown file embeds, relative to docs/screenshots */
function embeddedScreenshots(string $file): array
{
    preg_match_all('#screenshots/([a-z0-9-]+/[a-z0-9-]+)\.png#', file_get_contents(base_path($file)), $matches);

    return array_values(array_unique($matches[1]));
}

it('has a committed screenshot for every page of the E2E run', function () {
    $pages = screenshotPages();
    expect($pages)->not->toBeEmpty();

    foreach ($pages as $page) {
        expect(base_path("docs/screenshots/{$page}.png"))->toBeFile();
    }
});

it('embeds only existing screenshots in the documentation', function () {
    foreach (['README.md', 'docs/MODULE.md', 'docs/README.md', 'docs/SCREENSHOTS.md', 'docs/ANWESENHEIT.md', 'docs/DATEIEN.md', 'docs/LOCATIONS.md'] as $file) {
        foreach (embeddedScreenshots($file) as $image) {
            expect(screenshotPages())->toContain($image);
        }
    }
});

it('shows every page in the module overview and the gallery', function () {
    foreach (['docs/MODULE.md', 'docs/SCREENSHOTS.md'] as $file) {
        expect(embeddedScreenshots($file))->toEqualCanonicalizing(screenshotPages());
    }
});
