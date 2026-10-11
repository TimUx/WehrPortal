<?php

namespace App\Support;

/**
 * Member data that is not needed for now. Switched off, the fields are neither shown nor saved
 * (profile and Admin → Kameraden) and nothing that uses them appears; the columns and stored
 * values stay, so switching a field back on brings everything back.
 */
final class MemberFields
{
    /** Mobile number: profile, admin form, shown at booking conflicts */
    public const PHONE = false;

    /** Birth date: profile (with "show my birthday"), admin form, birthday cards on start page and admin dashboard */
    public const BIRTH_DATE = false;

    /** Postal address: admin form (the profile fields were removed and would have to be added back) */
    public const ADDRESS = false;
}
