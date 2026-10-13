<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Digital signature uploads
    |--------------------------------------------------------------------------
    |
    | Every account — user or superadmin — may keep one digital signature on
    | their profile and stamp it onto the documents the system generates for
    | them. The limits below are enforced on the server (the browser-side check
    | is a courtesy, never the boundary).
    |
    | A transparent PNG is the recommended format for official documents, so it
    | is listed first in the guidance shown on the profile page.
    |
    */

    // The largest signature accepted, in kilobytes (5 MB).
    'signature_max_kb' => (int) env('SIGNATURE_MAX_KB', 5120),

    // Accepted image formats. PNG first: it is the one that supports the
    // transparency official documents want.
    'signature_mimes' => ['png', 'jpg', 'jpeg', 'webp'],

    // The formats named in the on-page instructions, in the order shown.
    'signature_format_hint' => 'PNG, JPG or WEBP',

];
