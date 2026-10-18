<?php

namespace Extensions\Hydrantenkontrolle\Support;

class Checklist
{
    public const OK = 'Unterflurhydrant in Ordnung';

    public const DEFECTS = [
        'not_found' => 'Unterflurhydrant nicht aufgefunden',
        'out_of_service' => 'Unterflurhydrant außer Betrieb gesetzt',
        'cover_stuck' => 'Hydrantendeckel lässt sich nicht öffnen',
        'bridge_broken' => 'Steg vom Hydrantendeckel gebrochen',
        'cover_not_closing' => 'Hydrantendeckel schließt nicht',
        'foot_valve_leaking' => 'Fußventil undicht',
        'not_draining' => 'Hydrant entwässert nicht',
        'bolt_missing' => 'Bolzen von Deckel nicht vorhanden',
        'standpipe_blocked' => 'Standrohr/Schlüssel lassen sich nicht aufsetzen',
        'sign_problem' => 'Hinweisschild unleserlich/falsch/fehlt',
    ];
}
