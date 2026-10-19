<?php

namespace Extensions\Vereinsverwaltung\Http\Controllers;

use Extensions\Vereinsverwaltung\Services\MembershipService;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class MembershipApplicationController
{
    public function create(MembershipService $service): View
    {
        return view('vereinsverwaltung::public.application', [
            'associationNames' => $service->associationNames(),
        ]);
    }

    public function store(Request $request, MembershipService $service): RedirectResponse
    {
        if (filled($request->input('website'))) {
            return redirect()->route('verein.application')->with('submitted', true);
        }

        $input = $request->except(['website']);
        foreach (['youth_member', 'photo_consent', 'youth_photo_consent'] as $checkbox) {
            $input[$checkbox] = array_key_exists($checkbox, $input)
                ? filter_var($input[$checkbox], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : false;
        }
        if ($input['youth_member'] === true) {
            $input['account_holder'] = null;
            $input['bank_name'] = null;
            $input['bic'] = null;
            $input['iban'] = null;
        } else {
            $input['iban'] = strtoupper(preg_replace('/\s+/', '', (string) ($input['iban'] ?? '')));
            $input['bic'] = strtoupper(trim((string) ($input['bic'] ?? '')));
        }
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
            'association_name' => ['required', 'string', 'max:150', Rule::in($service->associationNames())],
            'youth_member' => ['required', 'boolean'],
            'account_holder' => [$input['youth_member'] === true ? 'nullable' : 'required', 'string', 'max:200'],
            'bank_name' => [$input['youth_member'] === true ? 'nullable' : 'required', 'string', 'max:200'],
            'bic' => [$input['youth_member'] === true ? 'nullable' : 'required', 'string', 'max:11', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i'],
            'iban' => [$input['youth_member'] === true ? 'nullable' : 'required', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/i'],
            'privacy_consent' => ['accepted'],
            'sepa_notice' => [$input['youth_member'] === true ? 'nullable' : 'accepted'],
            'photo_consent' => ['required', 'boolean'],
            'youth_photo_consent' => ['required', 'boolean'],
        ], [], [
            'first_name' => 'Vorname',
            'last_name' => 'Nachname',
            'birth_date' => 'Geburtsdatum',
            'email' => 'E-Mail',
            'street' => 'Straße und Hausnummer',
            'postal_code' => 'Postleitzahl',
            'city' => 'Wohnort',
            'association_name' => 'Verein',
            'account_holder' => 'Kontoinhaber',
            'bank_name' => 'Kreditinstitut',
            'bic' => 'BIC',
            'iban' => 'IBAN',
            'youth_member' => 'Jugend-/Kinderfeuerwehr',
            'privacy_consent' => 'Einwilligung zur Datenverarbeitung',
            'sepa_notice' => 'Hinweis zum SEPA-Mandat',
            'photo_consent' => 'Fotoeinwilligung der Erziehungsberechtigten beziehungsweise des Mitglieds',
            'youth_photo_consent' => 'Fotoeinwilligung des Jugendlichen',
        ]);

        if ($input['youth_member'] === true) {
            unset($input['sepa_notice']);
        }

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $data = $validator->validated();
        unset($data['privacy_consent'], $data['sepa_notice']);
        if (! $data['photo_consent'] || ! $data['youth_member']) {
            $data['youth_photo_consent'] = false;
        }
        if ($data['youth_member'] && $data['photo_consent'] && Carbon::parse($data['birth_date'])->age >= 14 && ! $data['youth_photo_consent']) {
            return back()->withErrors(['youth_photo_consent' => 'Bei Jugendlichen ab 14 Jahren ist zusätzlich deren eigene Einwilligung erforderlich. Wenn keine Fotos gewünscht sind, lassen Sie die Einwilligung der Erziehungsberechtigten ebenfalls unausgewählt.']);
        }
        $service->submitApplication($data);

        return redirect()->route('verein.application')->with('submitted', true);
    }
}
