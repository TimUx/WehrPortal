<?php

return [
    'enabled' => (bool) env('VEREINSVERWALTUNG_ENABLED', true),
    'association_name' => env('VEREIN_NAME', 'Feuerwehrverein'),
    'annual_fee_cents' => (int) env('VEREIN_ANNUAL_FEE_CENTS', 1500),
    'billing_month' => (int) env('VEREIN_BILLING_MONTH', 12),
    'billing_year_offset' => (int) env('VEREIN_BILLING_YEAR_OFFSET', 1),
    'creditor_id' => env('VEREIN_CREDITOR_ID'),
    'privacy_notice_version' => env('VEREIN_PRIVACY_NOTICE_VERSION', '1'),
    'photo_consent_version' => env('VEREIN_PHOTO_CONSENT_VERSION', '1'),
];
