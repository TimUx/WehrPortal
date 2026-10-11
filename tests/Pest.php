<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

// The booking tests use fixed dates in October 2026; the clock stands before them so they stay in the future
uses()->beforeEach(fn () => $this->travelTo(Carbon\Carbon::parse('2026-10-01 12:00', 'Europe/Berlin')))->in('Feature/Booking');
