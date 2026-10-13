<?php

use App\Http\Middleware\NoIndex;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

test('no page, file or feed of the app may be indexed by search engines or AI crawlers', function () {
    Module::where('key', 'kalender')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->get('/anmelden')->assertOk()->assertHeader('X-Robots-Tag', NoIndex::HEADER);
    $this->get('/admin')->assertRedirect()->assertHeader('X-Robots-Tag', NoIndex::HEADER);
    $this->get(route('kalender.public'))->assertOk()->assertHeader('X-Robots-Tag', NoIndex::HEADER)->assertSee('name="robots" content="noindex, nofollow"', false);
    $this->get('/gibt-es-nicht')->assertNotFound()->assertHeader('X-Robots-Tag', NoIndex::HEADER);
    $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()->assertHeader('X-Robots-Tag', NoIndex::HEADER);

    $robots = file_get_contents(public_path('robots.txt'));
    expect($robots)->toContain("User-agent: *\nDisallow: /")->toContain('GPTBot')->toContain('ClaudeBot')->toContain('Google-Extended');
});
