<?php

return [
    // Fixed "now" for the E2E screenshot run (tests/e2e, docs/SCREENSHOTS.md), e.g.
    // "2026-10-14T10:00:00+02:00". Empty = the real clock. Never applied in production.
    'frozen_now' => env('E2E_FROZEN_NOW'),
];
