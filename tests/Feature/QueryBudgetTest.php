<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\MemoizedSchema;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Counts the queries a callback runs */
function queriesOf(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('a setting that is not set is asked once, not on every call', function () {
    expect(queriesOf(fn () => Setting::get('nicht_gesetzt', 'Vorgabe')))->toBe(1)
        ->and(queriesOf(fn () => Setting::get('nicht_gesetzt', 'Vorgabe')))->toBe(0)
        ->and(Setting::get('nicht_gesetzt', 'Vorgabe'))->toBe('Vorgabe');

    Setting::set('nicht_gesetzt', 'jetzt doch');
    expect(Setting::get('nicht_gesetzt', 'Vorgabe'))->toBe('jetzt doch');

    Setting::forget('nicht_gesetzt');
    expect(Setting::get('nicht_gesetzt'))->toBeNull();
});

test('the schema is asked once per table and column, and again after it changed', function () {
    app(MemoizedSchema::class)->forget();

    expect(queriesOf(fn () => Schema::hasTable('users')))->toBe(1)
        ->and(queriesOf(fn () => Schema::hasTable('users')))->toBe(0)
        ->and(queriesOf(fn () => Schema::hasColumn('users', 'email')))->toBeGreaterThan(0)
        ->and(queriesOf(fn () => Schema::hasColumn('users', 'email')))->toBe(0)
        ->and(Schema::hasColumn('users', 'gibt_es_nicht'))->toBeFalse();

    // A missing table is never remembered, and creating or dropping one forgets everything
    expect(Schema::hasTable('test_memo'))->toBeFalse();
    Schema::create('test_memo', fn ($table) => $table->id());
    expect(Schema::hasTable('test_memo'))->toBeTrue();
    Schema::drop('test_memo');
    expect(Schema::hasTable('test_memo'))->toBeFalse();
});

test('role checks for a list of Kameraden are loaded at once and follow changes', function () {
    $members = User::factory()->count(5)->create();
    $roles = app(StandardRoleAssignments::class);

    $roles->preload($members->modelKeys());
    // Not one or two queries per Kamerad (that would be ten here); a shared lookup may remain
    expect(queriesOf(fn () => $members->each(fn (User $member) => $roles->hasAnyModuleRole($member, 'kalender', 'terminverwalter'))))->toBeLessThan(5);

    // Deactivating a Kamerad drops what was remembered
    $members->first()->update(['status' => 'left']);
    expect(queriesOf(fn () => $roles->canEnterModule($members->first()->fresh(), 'kalender')))->toBeGreaterThan(0);
});

test('the login page stays within a small number of queries', function () {
    $this->get(route('auth.magic-link.form'))->assertOk();

    expect(queriesOf(fn () => $this->get(route('auth.magic-link.form'))->assertOk()))->toBeLessThanOrEqual(15);
});
