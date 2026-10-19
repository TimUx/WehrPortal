<?php

namespace Extensions\Vereinsverwaltung\Http\Controllers;

use Extensions\Vereinsverwaltung\Services\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class MembershipApplicationController
{
    public function create(): View
    {
        return view('vereinsverwaltung::public.application');
    }

    public function store(Request $request, MembershipService $service): RedirectResponse
    {
        if (filled($request->input('website'))) {
            return redirect()->route('verein.application')->with('submitted', true);
        }

        $input = $request->except(['website']);
        $input['iban'] = strtoupper(preg_replace('/\s+/', '', (string) ($input['iban'] ?? '')));
        $input['bic'] = strtoupper(trim((string) ($input['bic'] ?? '')));
        $validator = Validator::make($input, [
            'title' => ['nullable', 'string', 'max:30'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before:today'],
            'marital_status' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:254'],
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:100'],
            'account_holder' => ['required', 'string', 'max:200'],
            'bank_name' => ['required', 'string', 'max:200'],
            'bic' => ['required', 'string', 'max:11', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i'],
            'iban' => ['required', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/i'],
            'privacy_consent' => ['accepted'],
            'sepa_notice' => ['accepted'],
        ], [], [
            'first_name' => 'Vorname',
            'last_name' => 'Nachname',
            'birth_date' => 'Geburtsdatum',
            'email' => 'E-Mail',
            'street' => 'Straße und Hausnummer',
            'postal_code' => 'Postleitzahl',
            'city' => 'Wohnort',
            'account_holder' => 'Kontoinhaber',
            'bank_name' => 'Kreditinstitut',
            'bic' => 'BIC',
            'iban' => 'IBAN',
            'privacy_consent' => 'Einwilligung zur Datenverarbeitung',
            'sepa_notice' => 'Hinweis zum SEPA-Mandat',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $data = $validator->validated();
        unset($data['privacy_consent'], $data['sepa_notice']);
        $service->submitApplication($data);

        return redirect()->route('verein.application')->with('submitted', true);
    }
}
