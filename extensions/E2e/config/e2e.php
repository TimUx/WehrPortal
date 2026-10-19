<?php

return [
    // Fixed "now" for the E2E screenshot run (tests/e2e, docs-extended/SCREENSHOTS.md), e.g.
    // "2026-10-12 10:00 Europe/Berlin". Empty = the real clock. Never applied in production.
    'frozen_now' => env('E2E_FROZEN_NOW'),
];
